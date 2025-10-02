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

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Log file paths
const LOG_FILE = path.join(__dirname, '../../../storage/logs/aptos/sync-process.log');
const ERROR_LOG_FILE = path.join(__dirname, '../../../storage/logs/aptos/sync-error.log');

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
dotenv.config();

// Define interfaces
interface SyncRequest {
    user_id: number;
    owner_address: string;
    channel: string;
    channel_user_id: string;
}

interface SyncResult {
    success: boolean;
    user_id?: number;
    tx_hash?: string;
    error?: string;
}

// Initialize Aptos client
const config = new AptosConfig({ 
    network: process.env.APTOS_NETWORK as Network || Network.TESTNET 
});
const aptos = new Aptos(config);

// Load service account (admin) from environment
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

/**
 * Sync user channel on the Aptos smart contract
 */
async function syncUser(request: SyncRequest): Promise<SyncResult> {
    try {

        logToFile(`Starting channel sync for user ${request.user_id}`);
        logToFile(`User aptos address: ${request.owner_address}`);
        logToFile(`Channel: ${request.channel}, Channel User ID: ${request.channel_user_id}`);

        // Build the transaction
        const transaction = await aptos.transaction.build.simple({
            sender: serviceAccount.accountAddress,
            data: {
                function: `${MODULE_ADDRESS}::aptsend::sync_user`,
                typeArguments: [],
                functionArguments: [
                    request.owner_address,                    // user_address
                    Buffer.from(request.channel),             // channel (vector<u8>)
                    Buffer.from(request.channel_user_id)      // channel_user_id (vector<u8>)
                ],
            },
        });

        logToFile(`Transaction built successfully`);

        // Sign and submit the transaction
        const committedTransaction = await aptos.signAndSubmitTransaction({
            signer: serviceAccount,
            transaction,
        });

        logToFile(`Transaction submitted: ${committedTransaction.hash}`);

        // Wait for confirmation
        const executedTransaction = await aptos.waitForTransaction({
            transactionHash: committedTransaction.hash,
        });

        if (!executedTransaction.success) {
            throw new Error(`Transaction failed on-chain: ${executedTransaction.vm_status}`);
        }

        logToFile(`Transaction confirmed: ${committedTransaction.hash}`);
        logToFile(`Gas used: ${executedTransaction.gas_used}`);

        return {
            success: true,
            user_id: request.user_id,
            tx_hash: committedTransaction.hash,
        };

    } catch (error: any) {
        const errorMessage = `Sync failed for user ${request.user_id}: ${error.message}`;
        logToFile(errorMessage, true);
        
        return {
            success: false,
            user_id: request.user_id,
            error: error.message || "Unknown error",
        };
    }
}

/**
 * Main execution
 */
async function main() {
    try {
        logToFile("=== APTOS CHANNEL SYNC SCRIPT STARTED ===");
        
        // Get request data from command line argument
        const requestJson = process.argv[2];
        
        if (!requestJson) {
            throw new Error("No request data provided");
        }

        logToFile(`Received request: ${requestJson}`);

        // Parse the request
        const request: SyncRequest = JSON.parse(requestJson);

        // Validate request
        if (!request.vault_address || !request.channel || !request.channel_user_id) {
            throw new Error("Missing required fields in request");
        }

        // Process the sync
        const result = await syncUser(request);

        // Output result as JSON for Laravel to parse
        console.log(JSON.stringify(result));

        if (result.success) {
            logToFile("=== SYNC COMPLETED SUCCESSFULLY ===");
            process.exit(0);
        } else {
            logToFile("=== SYNC FAILED ===", true);
            process.exit(1);
        }

    } catch (error: any) {
        const errorMessage = `Script failed: ${error.message}`;
        logToFile(errorMessage, true);
        
        // Output error as JSON
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
        
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