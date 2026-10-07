<?php

namespace App\Console\Commands\Notifications;

use App\Services\ReviewPromptService;
use Illuminate\Console\Command;

/** Hourly: "rate your purchase" N days after delivery (config notifications.review_prompt_delay_days). */
class SendReviewReminders extends Command
{
    protected $signature   = 'notifications:review-reminders';
    protected $description = 'Remind buyers to review the items delivered N days ago (once per item)';

    public function handle(ReviewPromptService $prompts): int
    {
        $this->info('Review reminders sent: ' . $prompts->remindDue());
        return self::SUCCESS;
    }
}
