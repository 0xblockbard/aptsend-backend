<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Cache;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ScrapeTweetsJob;
use App\Services\AptosTransferService;

// Process Aptos transfers every minute
Schedule::call(function () {
    $transferService = app(AptosTransferService::class);
    
    // Check if there are any unprocessed transfers
    if (!$transferService->hasUnprocessedTransfers()) {
        return;
    }
    
    $count = $transferService->getUnprocessedCount();
    $cacheKey = "process_aptos_transfer";
    
    // Check if a job is already in progress
    if (!Cache::get($cacheKey)) {
        // Set a cache key to prevent duplicate dispatches (2 minute lock)
        Cache::put($cacheKey, true, now()->addMinutes(2));
        
        \Log::info("ProcessTransferJob dispatched at " . now() . " ({$count} pending)");
        ProcessTransferJob::dispatch();
    }
})->everyMinute();

// Scrape tweets every 15 minutes
Schedule::call(function () {
    $cacheKey = "scrape_tweets";
    
    // Check if a scraping job is already in progress
    if (!Cache::get($cacheKey)) {
        // Set a cache key to prevent duplicate dispatches (20 minute lock)
        Cache::put($cacheKey, true, now()->addMinutes(20));
        
        \Log::info("ScrapeTweetsJob dispatched at " . now());
        ScrapeTweetsJob::dispatch();
    }
})->everyFifteenMinutes();

// Alternative: Dispatch jobs directly (cleaner approach)
// Schedule::job(new ProcessTransferJob())->everyMinute();
// Schedule::job(new ScrapeTweetsJob())->everyFifteenMinutes();