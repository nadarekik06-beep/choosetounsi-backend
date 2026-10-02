<?php

namespace App\Console\Commands;

use App\Services\Forecast\ForecastAlerts;
use App\Services\Forecast\ForecastService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Weekly forecast summary to sellers who turned it on (Outils IA → Ventes → Réglages). */
class ForecastDigest extends Command
{
    protected $signature = 'forecast:digest {--seller=* : Only these seller ids}';

    protected $description = 'Send the weekly forecast digest (opt-in)';

    public function handle(ForecastService $service, ForecastAlerts $alerts): int
    {
        $ids = DB::table('forecast_settings')->whereNull('product_id')->where('weekly_digest', true)->pluck('seller_id')
            ->map(fn($id) => (int) $id)->all();
        if ($this->option('seller')) $ids = array_intersect($ids, array_map('intval', $this->option('seller')));
        $eligible = array_flip($service->eligibleSellerIds());

        $sent = 0;
        foreach ($ids as $sellerId) {
            if (!isset($eligible[$sellerId])) continue;
            $shop = $service->latest($sellerId, 0);
            if ($shop && $alerts->sendDigest($sellerId, $shop)) $sent++;
        }
        $this->info("{$sent} digest(s) sent");
        return self::SUCCESS;
    }
}
