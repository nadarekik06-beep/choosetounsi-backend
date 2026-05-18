<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Explicitly registered commands.
     * The load() call in commands() already auto-discovers everything in
     * app/Console/Commands/, so this array is only needed if you want to
     * be explicit or if auto-discovery ever fails.
     */
    protected $commands = [
        \App\Console\Commands\BlackDailyNotify::class,
        \App\Console\Commands\BackfillSellerOrderFinancials::class,

    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        // ── Promotions sync (existing) ─────────────────────────────────────
        $schedule->command('promotions:sync')->everyMinute();

        // ── Black Pepper — daily smart notifications ───────────────────────
        // Runs every day at 08:00 server time.
        // Sends: auto-promo, stock-risk, weekend-spike, cooling notifications
        // to all active Black Pepper sellers.
        //
        // Test manually: php artisan black:daily-notify
        // Verify schedule: php artisan schedule:list
        $schedule->command('black:daily-notify')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->onSuccess(function () {
                \Illuminate\Support\Facades\Log::info('[Kernel] black:daily-notify completed successfully.');
            })
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('[Kernel] black:daily-notify FAILED.');
            });
    }

    /**
     * Register the commands for the application.
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}