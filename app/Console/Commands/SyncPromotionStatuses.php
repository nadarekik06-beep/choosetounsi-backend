<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Promotion;
use Carbon\Carbon;

class SyncPromotionStatuses extends Command
{
    protected $signature   = 'promotions:sync';
    protected $description = 'Activate scheduled promotions and expire ended ones';

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

        $this->info("Activated: {$activated} | Expired: {$expired}");
    }
}