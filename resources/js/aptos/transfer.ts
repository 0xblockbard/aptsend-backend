import { 
    Aptos, 
    AptosConfig, 
    Network, 
    Account, 
    Ed25519PrivateKey 
} from '@aptos-labs/ts-sdk';
import * as dotenv from "dotenv";
import * as fs from "fs";
import * as path from "path";
import { fileURLToPath } from 'url';
import { dirname } from 'path';
import * as mysql from 'mysql2/promise';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Log file paths
const LOG_FILE = path.join(__dirname, '../../../storage/logs/aptos/transfer-process.log');
const ERROR_LOG_FILE = path.join(__dirname, '../../../storage/logs/aptos/transfer-error.log');

// Function to log to file
function logToFile(message: string, isError: boolean = false) {
    const timestamp = new Date().toISOString();
    const logMessage = `[${timestamp}] ${message}\n`;
    
    try {
        fs.appendFileSync(isError ? ERROR_LOG_FILE : LOG_FILE, logMessage);
        
        if (isError) {
            console.error(message);
        } else {
            console.log(message);
        }
    } catch (error) {
        console.error(`Failed to write to log file: ${error}`);
    }
}

// Load environment variables
dotenv.config({ quiet: true });

// Define interface
interface TransferRequest {
    id: number;
    from_channel: string;
    from_user_id: string;
    to_channel: string;
    to_user_id: string;
    amount: number;
}

// Transfer status constants (matching Laravel model)
const STATUS_FAILED = 0;
const STATUS_COMPLETED = 1;
const STATUS_PENDING = 2;
const STATUS_PROCESSING = 3;

// Initialize Aptos client
const config = new AptosConfig({ 
    network: process.env.APTOS_NETWORK as Network || Network.TESTNET 
});
const aptos = new Aptos(config);

// Load service account from environment
const SERVICE_PRIVATE_KEY = process.env.APTOS_SERVICE_SIGNER_PRIVATE_KEY;
const MODULE_ADDRESS = process.env.APTOS_MODULE_ADDRESS;

if (!SERVICE_PRIVATE_KEY) {
    logToFile("APTOS_SERVICE_SIGNER_PRIVATE_KEY not found in environment", true);
    process.exit(1);
}

if (!MODULE_ADDRESS) {
    logToFile("APTOS_MODULE_ADDRESS not found in environment", true);
    process.exit(1);
}

// Create service account from private key
const privateKey = new Ed25519PrivateKey(SERVICE_PRIVATE_KEY);
const serviceAccount = Account.fromPrivateKey({ privateKey });

logToFile(`Service account loaded: ${serviceAccount.accountAddress.toString()}`);

// Database configuration
const dbConfig = {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USERNAME || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_DATABASE || 'laravel',
    port: parseInt(process.env.DB_PORT || '3306')
};

/**
 * Process a single transfer
 */
async function processTransfer(request: TransferRequest): Promise<void> {
    try {
        logToFile(`Processing transfer ${request.id}: ${request.from_channel}:${request.from_user_id} -> ${request.to_channel}:${request.to_user_id}, Amount: ${request.amount}`);

        // Determine which entry function to use
        const isSameChannel = request.from_channel === request.to_channel;
        
        let transaction;
        
        if (isSameChannel) {
            logToFile(`Transfer ${request.id}: Using transfer_within_channel`);
            
            transaction = await aptos.transaction.build.simple({
                sender: serviceAccount.accountAddress,
                data: {
                    function: `${MODULE_ADDRESS}::aptsend::transfer_within_channel`,
                    typeArguments: [],
                    functionArguments: [
                        Buffer.from(request.from_channel),
                        Buffer.from(request.from_user_id),
                        Buffer.from(request.to_user_id),
                        request.amount
                    ],
                },
            });
        } else {
            logToFile(`Transfer ${request.id}: Using process_transfer`);
            
            transaction = await aptos.transaction.build.simple({
                sender: serviceAccount.accountAddress,
                data: {
                    function: `${MODULE_ADDRESS}::aptsend::process_transfer`,
                    typeArguments: [],
                    functionArguments: [
                        Buffer.from(request.from_channel),
                        Buffer.from(request.from_user_id),
                        Buffer.from(request.to_channel),
                        Buffer.from(request.to_user_id),
                        request.amount
                    ],
                },
            });
        }

        // Sign and submit the transaction
        const committedTransaction = await aptos.signAndSubmitTransaction({
            signer: serviceAccount,
            transaction,
        });

        logToFile(`Transfer ${request.id}: Transaction submitted: ${committedTransaction.hash}`);

        // Wait for confirmation
        const executedTransaction = await aptos.waitForTransaction({
            transactionHash: committedTransaction.hash,
        });

        if (!executedTransaction.success) {
            throw new Error(`Transaction failed on-chain: ${executedTransaction.vm_status}`);
        }

        logToFile(`Transfer ${request.id}: Transaction confirmed: ${committedTransaction.hash}`);

        // Update database - mark as completed
        await updateTransferInDB(request.id, {
            success: true,
            tx_hash: committedTransaction.hash,
        });

        logToFile(`Transfer ${request.id}: Successfully completed`);

    } catch (error: any) {
        const errorMessage = `Transfer ${request.id} failed: ${error.message}`;
        logToFile(errorMessage, true);
        
        // Update database - mark as failed
        await updateTransferInDB(request.id, {
            success: false,
            error: error.message || "Unknown error",
        });
    }
}

/**
 * Update transfer in database
 */
async function updateTransferInDB(transferId: number, result: { success: boolean; tx_hash?: string; error?: string }) {
    try {
        const connection = await mysql.createConnection(dbConfig);

        const now = new Date().toISOString().slice(0, 19).replace('T', ' ');

        if (result.success) {
            // Update as completed (status = 1)
            await connection.execute(
                `UPDATE transfers 
                SET tx_hash = ?, status = ?, processed_at = ? 
                WHERE id = ?`,
                [result.tx_hash, STATUS_COMPLETED, now, transferId]
            );
        } else {
            // Update as failed (status = 0)
            const errorMessage = result.error || "Unknown error";
            const truncatedError = errorMessage.length > 255 
                ? errorMessage.slice(0, 252) + '...' 
                : errorMessage;
            
            const errorArray = JSON.stringify([{
                message: truncatedError,
                timestamp: new Date().toISOString()
            }]);

            await connection.execute(
                `UPDATE transfers 
                SET status = ?, error_message = ?, processed_at = ? 
                WHERE id = ?`,
                [STATUS_FAILED, errorArray, now, transferId]
            );
        }

        await connection.end();
        logToFile(`✅ Transfer ${transferId} updated in database`);

    } catch (error) {
        logToFile(`❌ Failed to update transfer ${transferId} in database: ${error}`, true);
    }
}

/**
 * Main execution
 */
async function main() {
    try {
        logToFile("=== APTOS TRANSFER SCRIPT STARTED ===");
        
        const requestJson = process.argv[2];
        
        if (!requestJson) {
            throw new Error("No request data provided");
        }

        // Parse the single transfer request
        const request: TransferRequest = JSON.parse(requestJson);
        
        logToFile(`Processing single transfer: ID ${request.id}`);
        
        await processTransfer(request);
        
        logToFile("=== APTOS TRANSFER SCRIPT COMPLETED ===");
        process.exit(0);

    } catch (error: any) {
        logToFile(`Script failed: ${error.message}`, true);
        process.exit(1);
    }
}

// Make sure log directories exist
try {
    const logDir = path.dirname(LOG_FILE);
    if (!fs.existsSync(logDir)) {
        fs.mkdirSync(logDir, { recursive: true });
    }
} catch (error) {
    console.error(`Failed to create log directory: ${error}`);
}

// Run the script
main();