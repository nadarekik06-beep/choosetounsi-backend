<?php

namespace App\Console\Commands;

use App\Models\ProfitAlertSetting;
use App\Models\RevenueGoal;
use App\Services\Profit\GoalAlerts;
use App\Services\Profit\SellerRevenueService;
use Illuminate\Console\Command;

/**
 * Centre de profit goal alerts (scheduled in App\Console\Kernel).
 *
 *   php artisan profit:goal-alerts --task=milestones   hourly safety net
 *   php artisan profit:goal-alerts --task=pace         daily 10:00 (Tunis)
 *   php artisan profit:goal-alerts --task=weekly       Monday 08:30
 *   php artisan profit:goal-alerts --task=monthly      the 1st, 09:00 (recap + new goal reminder)
 *
 * Testing: --seller=ID limits to one seller, --force skips the once-per-period
 * guards (cooldown, day of month, already sent this week/month).
 */
class ProfitGoalAlerts extends Command
{
    protected $signature = 'profit:goal-alerts
        {--task=all : milestones|pace|weekly|monthly|all}
        {--seller= : only this seller id}
        {--force : ignore cooldowns and once-per-period guards}';

    protected $description = 'Send Centre de profit goal alerts (milestones, pace, weekly summary, monthly recap).';

    public function handle(GoalAlerts $alerts, SellerRevenueService $revenue): int
    {
        $task  = (string) $this->option('task');
        $force = (bool) $this->option('force');
        $now   = $revenue->now();
        $ym    = $now->format('Y-m');
        $prev  = $now->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        $recent = $now->startOfMonth()->subMonthsNoOverflow(3)->format('Y-m');

        $only = $this->option('seller') ? [(int) $this->option('seller')] : null;
        $pick = fn($ids) => collect($only ?? $ids)->unique()->values();
        $sent = [];

        if (in_array($task, ['milestones', 'all'], true)) {
            foreach ($pick(RevenueGoal::where('month', $ym)->pluck('seller_id')) as $id) {
                if ($alerts->checkMilestones($id, $now) !== null) $sent['milestones'][] = $id;
            }
        }
        if (in_array($task, ['pace', 'all'], true)) {
            foreach ($pick(RevenueGoal::where('month', $ym)->pluck('seller_id')) as $id) {
                if ($alerts->checkPace($id, $now, $force)) $sent['pace'][] = $id;
            }
        }
        if (in_array($task, ['weekly', 'all'], true)) {
            foreach ($pick(ProfitAlertSetting::where('weekly', true)->where('enabled', true)->pluck('seller_id')) as $id) {
                if ($alerts->weekly($id, $now, $force)) $sent['weekly'][] = $id;
            }
        }
        if (in_array($task, ['monthly', 'all'], true)) {
            if ($force || (int) $now->format('j') === 1) {
                foreach ($pick(RevenueGoal::where('month', $prev)->pluck('seller_id')) as $id) {
                    if ($alerts->monthlyRecap($id, $now, $force)) $sent['recap'][] = $id;
                }
                foreach ($pick(RevenueGoal::where('month', '>=', $recent)->where('month', '<', $ym)->pluck('seller_id')) as $id) {
                    if ($alerts->newGoalReminder($id, $now, $force)) $sent['new_goal'][] = $id;
                }
            } else {
                $this->line('monthly: only runs on the 1st (use --force to test).');
            }
        }

        foreach ($sent as $kind => $ids) $this->info("{$kind}: " . count($ids) . ' seller(s) notified [' . implode(', ', $ids) . ']');
        if (!$sent) $this->line('Nothing to send.');
        return self::SUCCESS;
    }
}
