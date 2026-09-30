<?php

namespace App\Console\Commands;

use App\Services\Ads\AdEmailService;
use App\Services\Ads\AdSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Weekly "Picked for you" e-mail to opted-in shoppers (queued; needs queue:work).
 * Scheduled on ads.digest_day at ads.digest_time, Africa/Tunis.
 *
 *   php artisan ads:send-digest [--user=ID] [--limit=N]
 */
class SendAdDigest extends Command
{
    protected $signature   = 'ads:send-digest {--user= : Only this user id} {--limit=0 : Stop after N e-mails}';
    protected $description = 'Queue the weekly "Picked for you" marketing e-mail for opted-in users';

    public function handle(AdEmailService $emails, AdSettings $settings): int
    {
        if (!$settings->get('digest_enabled')) {
            $this->info('Digest disabled (ads.digest_enabled).');
            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $sent  = 0;
        $emails->eligibleUsers()
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($emails, $limit, &$sent) {
                foreach ($users as $user) {
                    try {
                        $sent += (int) $emails->sendDigest($user);
                    } catch (\Throwable $e) {
                        Log::warning("[ads:send-digest] user #{$user->id}: " . $e->getMessage());
                    }
                    if ($limit && $sent >= $limit) {
                        return false;
                    }
                }
            });

        $this->info("Queued {$sent} digest e-mail(s).");
        return self::SUCCESS;
    }
}
