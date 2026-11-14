<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Models\User;
use App\Notifications\Returns\ReturnAdminNotification;
use App\Notifications\Returns\ReturnSellerNotification;
use App\Services\Returns\ReturnPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Returns policy v2 watchdog (idempotent: each notice is sent once, stamped on the complaint).
 *   - picked up N days ago and the shop never clicked « Colis retour reçu »  → admin (can start the inspection)
 *   - received, inspection due tomorrow                                      → shop reminder
 *   - received, not inspected by the due date                                → admin
 */
class ReturnsWatch extends Command
{
    protected $signature = 'returns:watch';
    protected $description = 'Returns v2: notify the admin when a return parcel is not confirmed / inspected in time';

    public function handle(ReturnPolicy $policy): int
    {
        $s = $policy->settings();
        $admins = fn () => User::where('role', 'admin')->where('is_active', true)->get();
        $n = 0;

        Complaint::where('policy_version', 2)->where('status', Complaint::STATUS_PICKED_UP)
            ->whereNull('receive_timeout_notified_at')
            ->where('picked_up_at', '<=', now()->subDays($s['receive_timeout_days']))
            ->each(function (Complaint $c) use ($admins, &$n) {
                Notification::send($admins(), new ReturnAdminNotification($c, 'receive_timeout'));
                $c->forceFill(['receive_timeout_notified_at' => now()])->save();
                $n++;
            });

        Complaint::where('policy_version', 2)->where('status', Complaint::STATUS_RETURNED_TO_SELLER)
            ->whereNull('inspection_result')->whereNotNull('inspection_due_at')
            ->each(function (Complaint $c) use ($admins, &$n) {
                if (!$c->inspection_reminder_sent_at && $c->inspection_due_at->lte(now()->addDay()) && $c->inspection_due_at->isFuture()) {
                    if ($seller = User::find($c->seller_id)) {
                        $seller->notify(new ReturnSellerNotification($c, 'inspection_reminder'));
                    }
                    $c->forceFill(['inspection_reminder_sent_at' => now()])->save();
                    $n++;
                }
                if (!$c->inspection_timeout_notified_at && $c->inspection_due_at->lte(now())) {
                    Notification::send($admins(), new ReturnAdminNotification($c, 'inspection_timeout'));
                    $c->forceFill(['inspection_timeout_notified_at' => now()])->save();
                    $n++;
                }
            });

        $this->info("{$n} notice(s) sent.");
        return self::SUCCESS;
    }
}
