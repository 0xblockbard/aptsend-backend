import dotenv from 'dotenv';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { dirname } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Find project root by looking for package.json
function findProjectRoot(startPath: string): string {
  let currentPath = startPath;
  
  while (currentPath !== path.parse(currentPath).root) {
    if (fs.existsSync(path.join(currentPath, 'package.json'))) {
      return currentPath;
    }
    currentPath = path.dirname(currentPath);
  }
  
  throw new Error('Could not find project root (package.json not found)');
}

const PROJECT_ROOT = findProjectRoot(__dirname);
const ENV_PATH = path.join(PROJECT_ROOT, '.env');

// Load environment variables with absolute path
console.error(`DEBUG: Loading .env from: ${ENV_PATH}`);
console.error(`DEBUG: .env exists: ${fs.existsSync(ENV_PATH)}`);

dotenv.config({ quiet: true, path: ENV_PATH });

// Verify Bearer Token loaded
if (!process.env.TWITTER_BEARER_TOKEN) {
  console.error('ERROR: TWITTER_BEARER_TOKEN not found in environment');
  console.error(`Tried to load from: ${ENV_PATH}`);
  process.exit(1);
}

// Log file paths using PROJECT_ROOT
const LOG_FILE = path.join(PROJECT_ROOT, 'storage/logs/scraper/process.log');
const ERROR_LOG_FILE = path.join(PROJECT_ROOT, 'storage/logs/scraper/error.log');

// Configuration from env
const MAX_RESULTS = parseInt(process.env.SCRAPER_MAX_RESULTS_PER_REQUEST || '10');
const COMMAND_HASHTAG = process.env.SCRAPER_COMMAND_HASHTAG || 'aptsend';
const BEARER_TOKEN = process.env.TWITTER_BEARER_TOKEN;

interface ScraperResult {
  success: boolean;
  tweets?: Array<any>;
  count?: number;
  error?: string;
}

/**
 * Log to file
 */
function logToFile(message: string, isError: boolean = false) {
  const timestamp = new Date().toISOString();
  const logMessage = `[${timestamp}] ${message}\n`;
  
  try {
    fs.appendFileSync(isError ? ERROR_LOG_FILE : LOG_FILE, logMessage);
  } catch (error) {
    console.error(`Failed to write to log file: ${error}`);
  }
}

/**
 * Check if tweet starts with the command hashtag
 */
function isValidCommand(tweetText: string): boolean {
  const trimmed = tweetText.trim();
  const hashtag = `#${COMMAND_HASHTAG}`;
  
  return trimmed.toLowerCase().startsWith(hashtag.toLowerCase());
}

async function main() {
  try {
    logToFile('=== TWITTER SCRAPER STARTED (Direct API) ===');
    logToFile(`Starting tweet scrape for #${COMMAND_HASHTAG}`);
    logToFile(`Max results: ${MAX_RESULTS}`);

    // Validate Bearer Token
    if (!BEARER_TOKEN) {
      throw new Error('TWITTER_BEARER_TOKEN not found in .env');
    }

    // Build query
    const query = `#${COMMAND_HASHTAG}`;
    const maxResults = Math.min(MAX_RESULTS, 100); // API allows max 100

    // Build URL with query parameters
    const url = new URL('https://api.x.com/2/tweets/search/recent');
    url.searchParams.append('query', query);
    url.searchParams.append('max_results', maxResults.toString());
    url.searchParams.append('tweet.fields', 'created_at,author_id,public_metrics');
    url.searchParams.append('expansions', 'author_id');
    url.searchParams.append('user.fields', 'username');

    logToFile(`API URL: ${url.toString()}`);

    // Make API request
    const response = await fetch(url.toString(), {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${BEARER_TOKEN}`,
      },
    });

    if (!response.ok) {
      const errorData = await response.text();
      
      // Handle rate limiting gracefully - DON'T fail the job
      if (response.status === 429) {
        logToFile(`Rate limited by Twitter API - will retry later`, true);
        
        // Return success with empty tweets instead of failing
        const result: ScraperResult = {
          success: true,
          tweets: [],
          count: 0,
        };
        
        console.log(JSON.stringify(result));
        logToFile('=== RATE LIMITED - RETURNING EMPTY ===');
        process.exit(0);
      }
      
      logToFile(`API Error: ${response.status} ${response.statusText}`, true);
      logToFile(`Error details: ${errorData}`, true);
      throw new Error(`API request failed: ${response.status} ${response.statusText}`);
    }

    const data = await response.json();
    
    logToFile(`API returned ${data.data?.length || 0} tweets`);

    if (!data.data || data.data.length === 0) {
      logToFile('No tweets found');
      
      const result: ScraperResult = {
        success: true,
        tweets: [],
        count: 0,
      };

      console.log(JSON.stringify(result));
      logToFile('=== SCRAPING COMPLETED (NO RESULTS) ===');
      process.exit(0);
    }

    // Map user IDs to usernames
    const users = data.includes?.users || [];
    const userMap = new Map(users.map((u: any) => [u.id, u.username]));

    // Process tweets
    const allTweets = data.data.map((tweet: any) => {
      const username = userMap.get(tweet.author_id) || 'unknown';
      
      return {
        id: tweet.id,
        text: tweet.text,
        username: username,
        userId: tweet.author_id,
        created_at: tweet.created_at || new Date().toISOString(),
        likes: tweet.public_metrics?.like_count || 0,
        retweets: tweet.public_metrics?.retweet_count || 0,
        replies: tweet.public_metrics?.reply_count || 0,
        photos: [],
        urls: [],
        hashtags: [],
      };
    });

    logToFile(`Processed ${allTweets.length} tweets`);

    // Filter to only tweets that start with #aptsend
    const validCommands = allTweets.filter((tweet: any) => isValidCommand(tweet.text));
    
    logToFile(`Valid commands found: ${validCommands.length} (filtered from ${allTweets.length})`);

    // Output result as JSON for Laravel
    const result: ScraperResult = {
      success: true,
      tweets: validCommands,
      count: validCommands.length,
    };

    console.log(JSON.stringify(result));
    logToFile('=== SCRAPING COMPLETED SUCCESSFULLY ===');
    process.exit(0);

  } catch (error: any) {
    const errorMessage = `Script failed: ${error.message}`;
    logToFile(errorMessage, true);
    
    // Output error as JSON
    console.log(JSON.stringify({
      success: false,
      error: error.message
    }));
    
    logToFile('=== SCRAPING FAILED ===', true);
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