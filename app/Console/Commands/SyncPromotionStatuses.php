<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Promotion;
use App\Services\PromotionService;
use Carbon\Carbon;

class SyncPromotionStatuses extends Command
{
    protected $signature   = 'promotions:sync';
    protected $description = 'Update promotion statuses for seller/admin screens and release flash quota of cancelled orders';

    public function handle(): void
    {
        $now = Carbon::now();

        // Activate: scheduled → active when start time reached
        $activated = Promotion::where('status', 'scheduled')
            ->where('starts_at', '<=', $now)
            ->where('ends_at',   '>',  $now)
            ->update(['status' => 'active']);

        // Expire: active/scheduled → expired when end time passed
        $expired = Promotion::whereIn('status', ['active', 'scheduled'])
            ->where('ends_at', '<=', $now)
            ->update(['status' => 'expired']);

        // Pricing never reads these statuses (PromotionService decides by dates);
        // they only keep the seller/admin lists accurate.

        // Safety net: cancellations written without the observer
        $released = app(PromotionService::class)->releaseCancelled();

        $this->info("Activated: {$activated} | Expired: {$expired} | Flash units released: {$released}");
    }
}