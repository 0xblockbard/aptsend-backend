<?php

namespace App\Jobs;

use App\Services\TweetScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScrapeTweetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 180; // 3 minutes
    public $maxExceptions = 1;

    public function __construct()
    {
        $this->tries = 1;
    }

    public function handle(TweetScraperService $scraperService)
    {
        Log::info("===== Scrape Tweets Job =====");
        
        try {
            $result = $scraperService->scrapeAndProcessTweets();
            
            if (isset($result['error'])) {
                Log::error("ScrapeTweetsJob failed: {$result['error']}");
                $this->fail(new \Exception($result['error']));
                return;
            }
            
            Log::info("ScrapeTweetsJob completed", [
                'processed' => $result['processed'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed']
            ]);
            
        } catch (\Exception $e) {
            Log::error("ScrapeTweetsJob exception", [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString()
            ]);
            
            $this->fail($e);
        }
    }
}