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
        \App\Console\Commands\SubscriptionSchedulerCommand::class,
        

    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {   $schedule->command('subscriptions:process')
       ->dailyAt('02:00')          // run at 2am — low traffic time
       ->withoutOverlapping()
       ->runInBackground()
       ->onSuccess(function () {
           \Illuminate\Support\Facades\Log::info('[Kernel] subscriptions:process OK');
       })
       ->onFailure(function () {
           \Illuminate\Support\Facades\Log::error('[Kernel] subscriptions:process FAILED');
       });
        // ── Promotions sync (existing) ─────────────────────────────────────
        $schedule->command('promotions:sync')->everyMinute();

        // ── Sponsoring ──────────────────────────────────────────────────────
        // Ends overdue sponsorships (read paths only filter, they never write).
        $schedule->command('ads:complete-ended')->everyFiveMinutes()->withoutOverlapping();
        // New ad day: spend resets, campaigns capped yesterday resume.
        $schedule->command('ads:reset-daily')->dailyAt('00:05')->timezone(config('ads.timezone'))->withoutOverlapping();
        // Campaigns pause / resume with their product's stock and listing.
        $schedule->command('ads:stock-watch')->everyFifteenMinutes()->withoutOverlapping();
        // Campaign check-up: tips, placement weights, low-performance alerts.
        $schedule->command('ads:optimize')->dailyAt('04:00')->timezone(config('ads.timezone'))->withoutOverlapping()->runInBackground();
        // Click flags, attributions, daily stats and campaign counters rebuilt from raw data.
        $schedule->command('ads:rebuild-stats')->dailyAt('03:45')->timezone(config('ads.timezone'))->withoutOverlapping()->runInBackground();
        // Marketing e-mails (opt-in only; queued — needs php artisan queue:work).
        $digest = $this->digestSchedule();
        $schedule->command('ads:send-digest')
            ->weeklyOn($digest['day'], $digest['time'])->timezone(config('ads.timezone'))
            ->withoutOverlapping()->runInBackground();
        $schedule->command('ads:send-interest-emails')->dailyAt('11:00')->timezone(config('ads.timezone'))
            ->withoutOverlapping()->runInBackground();
        // Plan tiers' free ad credit for the month (previous credit expires).
        $schedule->command('ads:grant-monthly-credit')
            ->monthlyOn(1, '00:10')->timezone(config('ads.timezone'))
            ->withoutOverlapping();

        // ── AI search / similarity index ────────────────────────────────────
        // Nightly rebuild so products added during the day get embeddings.
        $schedule->command('search:rebuild')
            ->dailyAt('02:30')->timezone(config('ads.timezone'))
            ->withoutOverlapping()->runInBackground();

        // ── Homepage personalization ───────────────────────────────────────
        // Profiles also rebuild lazily on demand; these just warm them and keep tables bounded.
        $schedule->command('recommendations:refresh-profiles')->dailyAt('03:30')->withoutOverlapping()->runInBackground();
        $schedule->command('recommendations:prune')->weeklyOn(1, '04:00')->withoutOverlapping()->runInBackground();

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

    /** Digest weekday / time from the admin ad settings (defaults if the DB isn't reachable). */
    private function digestSchedule(): array
    {
        $days = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        try {
            $settings = app(\App\Services\Ads\AdSettings::class);
            $day  = $days[strtolower((string) $settings->get('digest_day'))] ?? 5;
            $time = preg_match('/^\d{2}:\d{2}$/', (string) $settings->get('digest_time')) ? (string) $settings->get('digest_time') : '18:00';
        } catch (\Throwable $e) {
            [$day, $time] = [5, '18:00'];
        }
        return ['day' => $day, 'time' => $time];
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