<?php

namespace App\Console\Commands;

use App\Services\Recommendation\InterestProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds interest profiles for users active recently, so the first homepage
 * load of the day doesn't pay for the rebuild. Profiles still refresh lazily
 * on their own; this is just a warm-up.
 *
 *   php artisan recommendations:refresh-profiles            (users active in last 24h)
 *   php artisan recommendations:refresh-profiles --hours=72
 *   php artisan recommendations:refresh-profiles --user=15
 */
class RefreshInterestProfiles extends Command
{
    protected $signature = 'recommendations:refresh-profiles {--hours=24} {--user=}';
    protected $description = 'Rebuild homepage interest profiles for recently active users';

    public function handle(InterestProfileService $profiles): int
    {
        $userIds = $this->option('user')
            ? [(int) $this->option('user')]
            : DB::table('user_interactions')
                ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id')
                ->all();

        foreach ($userIds as $id) {
            $profiles->rebuild((int) $id, null);
        }

        $this->info('Rebuilt ' . count($userIds) . ' profile(s).');
        return self::SUCCESS;
    }
}
