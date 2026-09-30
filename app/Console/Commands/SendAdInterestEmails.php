<?php

namespace App\Console\Commands;

use App\Services\Ads\AdEmailService;
use App\Services\Ads\AdSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * "Still looking for X?" e-mails (queued): opted-in shoppers who viewed a
 * subcategory ≥ 3 times this week without buying, when a sponsored product there
 * is discounted or ships free. Daily at 11:00 Africa/Tunis.
 *
 *   php artisan ads:send-interest-emails [--user=ID]
 */
class SendAdInterestEmails extends Command
{
    protected $signature   = 'ads:send-interest-emails {--user= : Only this user id}';
    protected $description = 'Queue triggered "Still looking for…" deal e-mails for opted-in users';

    public function handle(AdEmailService $emails, AdSettings $settings): int
    {
        if (!$settings->get('interest_emails_enabled')) {
            $this->info('Interest e-mails disabled (ads.interest_emails_enabled).');
            return self::SUCCESS;
        }

        $sent = 0;
        $emails->eligibleUsers()
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            // Only people who browsed this week can qualify.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('user_interactions')
                ->whereColumn('user_interactions.user_id', 'users.id')
                ->where('user_interactions.event_type', 'view')
                ->where('user_interactions.created_at', '>=', now()->subDays(7)))
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($emails, &$sent) {
                foreach ($users as $user) {
                    try {
                        $sent += (int) $emails->sendInterest($user);
                    } catch (\Throwable $e) {
                        Log::warning("[ads:send-interest-emails] user #{$user->id}: " . $e->getMessage());
                    }
                }
            });

        $this->info("Queued {$sent} interest e-mail(s).");
        return self::SUCCESS;
    }
}
