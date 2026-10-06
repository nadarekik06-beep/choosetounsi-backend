<?php

namespace App\Console\Commands;

use App\Services\GrowthRadar\ResultMeasurer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Measures Growth Radar actions that ended at least config('growth.results.after_days') ago. */
class GrowthMeasure extends Command
{
    protected $signature = 'growth:measure {--no-notify : Do not send result notifications}';

    protected $description = 'Measure the results of applied Growth Radar actions';

    public function handle(ResultMeasurer $measurer): int
    {
        $n = 0;
        foreach ($measurer->due() as $action) {
            try {
                $r = $measurer->measure($action, !$this->option('no-notify'));
                $this->line(sprintf('Action %d (%s, seller %d): %s%s', $action->id, $action->kind, $action->seller_id, $r['verdict'],
                    isset($r['net_gain']) ? sprintf(', net %+.0f DT', $r['net_gain']) : ''));
                $n++;
            } catch (\Throwable $e) {
                Log::error("[GrowthRadar] Measuring action {$action->id} failed: " . $e->getMessage());
                $this->error("Action {$action->id}: " . $e->getMessage());
            }
        }
        $this->info("$n action(s) measured");
        return self::SUCCESS;
    }
}
