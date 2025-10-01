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
    vault_address: string;
    channel: string;
    channel_user_id: string;
}

interface SyncResult {