<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use App\Jobs\ProcessTransferJob;
use App\Jobs\ScrapeTweetsJob;
use App\Services\AptosTransferService;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
    * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        // Process Aptos transfers every 10 seconds
        // Only dispatch job if there are unprocessed transfers
        $schedule->call(function () {
            $transferService = app(AptosTransferService::class);
            
            // Check if there are any unprocessed transfers
            if (!$transferService->hasUnprocessedTransfers()) {
                return;
            }
            
            $count = $transferService->getUnprocessedCount();
            $cacheKey = "process_aptos_transfer";
            
            // Check if a job is already in progress
            if (!Cache::get($cacheKey)) {
                // Set a cache key to prevent duplicate dispatches
                Cache::put($cacheKey, true, now()->addSeconds(45));
                
                \Log::info("ProcessTransferJob dispatched at " . now() . " ({$count} pending)");
                ProcessTransferJob::dispatch();
            }
        })->everyThirtySeconds();

        // Scrape tweets every 2 minutes
        $schedule->call(function () {
            $cacheKey = "scrape_tweets";
            
            // Check if a scraping job is already in progress
            if (!Cache::get($cacheKey)) {
                // Set a cache key to prevent duplicate dispatches
                Cache::put($cacheKey, true, now()->addMinutes(3));
                
                \Log::info("ScrapeTweetsJob dispatched at " . now());
                ScrapeTweetsJob::dispatch();
            }
        })->everyFifteenMinutes();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
