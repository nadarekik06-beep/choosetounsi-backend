<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps user_interactions bounded: drops rows past the retention window
 * and guest profiles nobody has used for 30 days.
 */
class PruneInteractions extends Command
{
    protected $signature = 'recommendations:prune';
    protected $description = 'Delete old recommendation interactions and stale guest profiles';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('recommendations.retention_days', 365));

        $deleted = 0;
        do {
            // Chunked so a large backlog doesn't hold a long table lock.
            $batch = DB::table('user_interactions')->where('created_at', '<', $cutoff)->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $guests = DB::table('user_interest_profiles')
            ->whereNull('user_id')
            ->where('updated_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Pruned {$deleted} interaction(s) and {$guests} guest profile(s).");
        return self::SUCCESS;
    }
}
