<?php

namespace App\Services;

use App\Models\TweetCommand;
use App\Models\Transfer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class TweetScraperService
{
    protected IdentifierResolverService $identifierResolver;

    public function __construct(IdentifierResolverService $identifierResolver)
    {
        $this->identifierResolver = $identifierResolver;
    }

    /**
     * Scrape tweets and process them
     *
     * @return array
     */
    public function scrapeAndProcessTweets(): array
    {
        Log::info("Starting tweet scraping process");

        try {
            // Run the scraper script
            $tweets = $this->executeScraperScript();

            if (empty($tweets)) {
                Log::info('No tweets found to process');
                return ['processed' => 0, 'skipped' => 0, 'failed' => 0];
            }

            Log::info("Found " . count($tweets) . " tweets to process");

            $processed = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($tweets as $tweet) {
                try {
                    // Check if tweet already exists
                    if (TweetCommand::isDuplicate($tweet['id'])) {
                        Log::info("Tweet {$tweet['id']} already processed, skipping");
                        $skipped++;
                        continue;
                    }

                    // Parse the tweet command
                    $command = $this->parseTweetCommand($tweet['text']);

                    if (!$command) {
                        Log::warning("Failed to parse or validate tweet {$tweet['id']}: {$tweet['text']}");
                        $failed++;
                        continue;
                    }

                    // Try to resolve recipient identifier to channel user ID
                    try {
                        $recipientUserId = $this->identifierResolver->resolve(
                            $command['recipient_channel'],
                            $command['recipient_identifier']
                        );
                        
                        // Create tweet command with READY status
                        $tweetCommand = TweetCommand::create([
                            'tweet_id' => $tweet['id'],
                            'author_username' => $tweet['username'],
                            'author_user_id' => $tweet['userId'],
                            'raw_text' => $tweet['text'],
                            'tweet_created_at' => $tweet['created_at'],
                            'amount' => $this->convertToOctas($command['amount']),
                            'token' => $command['token'],
                            'to_channel' => $command['recipient_channel'],
                            'to_user_id' => $recipientUserId,
                            'status' => TweetCommand::STATUS_READY,
                            'processed' => TweetCommand::NOT_SENT,
                        ]);
                        
                        // Create corresponding Transfer record for ProcessTransferJob
                        Transfer::create([
                            'source_type' => 'twitter',
                            'from_channel' => 'twitter',
                            'from_user_id' => $tweet['userId'], // Sender's Twitter user ID
                            'to_channel' => $command['recipient_channel'],
                            'to_user_id' => $recipientUserId,
                            'amount' => $this->convertToOctas($command['amount']),
                            'token' => $command['token'],
                            'status' => Transfer::STATUS_PENDING,
                        ]);
                        
                        $processed++;
                        Log::info("Successfully processed tweet {$tweet['id']} - READY with Transfer created");
                        
                    } catch (\Exception $e) {
                        // Failed to resolve - mark as NEEDS_LOOKUP
                        Log::warning("Failed to resolve recipient for tweet {$tweet['id']}: {$e->getMessage()}");
                        
                        TweetCommand::create([
                            'tweet_id' => $tweet['id'],
                            'author_username' => $tweet['username'],
                            'author_user_id' => $tweet['userId'],
                            'raw_text' => $tweet['text'],
                            'tweet_created_at' => $tweet['created_at'],
                            'amount' => $this->convertToOctas($command['amount']),
                            'token' => $command['token'],
                            'to_channel' => $command['recipient_channel'],
                            'to_user_id' => $command['recipient_identifier'], // Store identifier for later lookup
                            'status' => TweetCommand::STATUS_NEEDS_LOOKUP,
                            'processed' => TweetCommand::NOT_SENT,
                        ]);
                        
                        $processed++;
                    }

                } catch (\Exception $e) {
                    Log::error("Error processing tweet {$tweet['id']}: {$e->getMessage()}");
                    $failed++;
                }
            }

            Log::info("Tweet processing complete", [
                'processed' => $processed,
                'skipped' => $skipped,
                'failed' => $failed
            ]);

            return [
                'processed' => $processed,
                'skipped' => $skipped,
                'failed' => $failed
            ];

        } catch (\Exception $e) {
            Log::error("Tweet scraping failed: {$e->getMessage()}");
            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute the scraper TypeScript script
     *
     * @return array
     */
    protected function executeScraperScript(): array
    {
        $scriptPath = base_path('run-scraper.sh');
        $processArgs = ['/bin/bash', $scriptPath];

        Log::info("About to execute scraper", [
            'script_path' => $scriptPath,
            'script_exists' => file_exists($scriptPath),
            'working_dir' => base_path(),
        ]);

        $process = new Process($processArgs);
        $process->setTimeout(120);
        $process->setWorkingDirectory(base_path());

        $process->run();

        $exitCode = $process->getExitCode();
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();

        // LOG EVERYTHING
        Log::info("Scraper execution result", [
            'exit_code' => $exitCode,
            'is_successful' => $process->isSuccessful(),
            'raw_output' => $output,
            'raw_error_output' => $errorOutput,
            'output_length' => strlen($output),
        ]);

        // ADD THESE DEBUG LOGS
        Log::info("Scraper script executed", [
            'exit_code' => $process->getExitCode(),
            'is_successful' => $process->isSuccessful(),
        ]);

        if (!$process->isSuccessful()) {
             Log::error("Scraper process failed", [
                'exit_code' => $process->getExitCode(),
                'error_output' => $process->getErrorOutput(),
                'output' => $process->getOutput(),
            ]);
            throw new ProcessFailedException($process);
        }

        $output = trim($process->getOutput());
        
        Log::info("Scraper raw output", ['output' => $output]); // ADD THIS
        
        if (empty($output)) {
            return [];
        }

        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Failed to parse scraper output", [
                'output' => $output,
                'error' => json_last_error_msg()
            ]);
            throw new \Exception("Failed to parse scraper output");
        }

        if (!$result['success']) {
            throw new \Exception($result['error'] ?? 'Scraper failed');
        }

        return $result['tweets'] ?? [];
    }

    /**
     * Parse tweet command text
     *
     * @param string $text
     * @return array|null
     */
    protected function parseTweetCommand(string $text): ?array
    {
        // Expected formats:
        // #aptsend [amount] [token] [identifier] [channel] (explicit channel)
        // #aptsend [amount] [token] [identifier] (infer channel from identifier)
        
        $text = trim($text);
        
        // Try pattern WITH explicit channel first
        $patternWithChannel = '/^#aptsend\s+(\d+\.?\d*)\s+(\w+)\s+(\S+)\s+(\w+)/i';
        
        if (preg_match($patternWithChannel, $text, $matches)) {
            $amount = floatval($matches[1]);
            $token = strtoupper($matches[2]);
            $identifier = $matches[3];
            $channel = strtolower($matches[4]);

            // Validate that identifier format matches the specified channel
            if (!$this->validateIdentifierForChannel($identifier, $channel)) {
                Log::warning("Identifier '{$identifier}' doesn't match channel '{$channel}' format");
                return null;
            }

            return [
                'amount' => $amount,
                'token' => $token,
                'recipient_identifier' => $identifier,
                'recipient_channel' => $channel
            ];
        }
        
        // Try pattern WITHOUT explicit channel (default to twitter)
        $patternWithoutChannel = '/^#aptsend\s+(\d+\.?\d*)\s+(\w+)\s+(\S+)/i';
        
        if (preg_match($patternWithoutChannel, $text, $matches)) {
            $amount = floatval($matches[1]);
            $token = strtoupper($matches[2]);
            $identifier = $matches[3];
            
            // Default to twitter since this is a tweet
            $channel = 'twitter';
            
            // Validate identifier is valid for twitter
            if (!$this->validateIdentifierForChannel($identifier, $channel)) {
                Log::warning("Identifier '{$identifier}' is not valid for Twitter (must start with @)");
                return null;
            }

            return [
                'amount' => $amount,
                'token' => $token,
                'recipient_identifier' => $identifier,
                'recipient_channel' => $channel
            ];
        }
        
        Log::warning("Tweet command doesn't match required format: {$text}");
        return null;
    }

    /**
     * Validate that identifier format matches the specified channel
     *
     * @param string $identifier
     * @param string $channel
     * @return bool
     */
    protected function validateIdentifierForChannel(string $identifier, string $channel): bool
    {
        switch ($channel) {
            case 'twitter':
                return str_starts_with($identifier, '@');
                
            case 'google':
                return str_contains($identifier, '@') && filter_var($identifier, FILTER_VALIDATE_EMAIL);
                
            case 'evm':
                return str_starts_with($identifier, '0x') && strlen($identifier) === 42;
                
            case 'sol':
                return preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $identifier);
                
            default:
                // Reject unsupported channels
                return false;
        }
    }

    /**
     * Convert APT amount to Octas
     *
     * @param float $apt
     * @return int
     */
    protected function convertToOctas(float $apt): int
    {
        return (int) ($apt * 100_000_000);
    }
}