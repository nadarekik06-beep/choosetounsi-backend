<?php

namespace App\Jobs;

use App\Services\Profit\GoalAlerts;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Milestone check after a sub-order enters or leaves a counted status.
 * Dispatched after the response (no queue worker needed); the hourly
 * profit:goal-alerts --task=milestones sweep catches anything missed.
 */
class CheckGoalMilestones
{
    use Dispatchable;

    public function __construct(public int $sellerId) {}

    public function handle(GoalAlerts $alerts): void
    {
        try {
            $alerts->checkMilestones($this->sellerId);
        } catch (\Throwable $e) {
            report($e);   // never break an order update
        }
    }
}
