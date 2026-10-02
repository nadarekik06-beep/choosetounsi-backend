<?php

namespace App\Services\Forecast;

use App\Models\User;
use App\Notifications\ForecastAlertNotification;
use App\Notifications\ForecastDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sends forecast alerts after a computation, at most once per thing:
 *   stockout   once per product/variant per 7 days, only for forecasts we trust
 *              (own / blend, or ≥ 3 orders) — a guess never triggers an e-mail
 *   event      once per event per seller, ~4–6 weeks before it starts
 *   sales_drop once per product per 14 days
 */
class ForecastAlerts
{
    private const MAX_PER_RUN = 5;

    public function process(int $sellerId, array $computed, array $settings, CarbonImmutable $today): int
    {
        if (!$settings['alerts_enabled']) return 0;
        $seller = User::find($sellerId);
        if (!$seller) return 0;

        $queue = [];
        foreach ($computed['products'] as $r) {
            $trusted = in_array($r['tier'], ['own', 'blend'], true) || $r['data']['orders'] >= 3;
            foreach ($r['actions'] as $a) {
                $p = $a['params'];
                if (in_array($a['type'], ['stockout', 'out_of_stock'], true) && $trusted
                    && $p['days_left'] <= $settings['stockout_alert_days']) {
                    $queue[] = ['stockout', "p{$p['product_id']}:v{$p['variant_id']}", 7, $p, $a['severity']];
                }
                if ($a['type'] === 'sales_drop') {
                    $queue[] = ['sales_drop', "p{$p['product_id']}", 14, $p, $a['severity']];
                }
            }
        }
        foreach ($computed['shop']['actions'] ?? [] as $a) {
            if ($a['type'] === 'event' && $a['params']['days_until'] >= 28 && $a['params']['days_until'] <= 42) {
                $queue[] = ['event', "e{$a['params']['event_id']}", 3650, $a['params'], 3];
            }
        }

        usort($queue, fn($x, $y) => $x[4] <=> $y[4]);
        $sent = 0;
        foreach ($queue as [$type, $ref, $days, $params]) {
            if ($sent >= self::MAX_PER_RUN) break;
            $recent = DB::table('forecast_alert_log')->where('seller_id', $sellerId)->where('type', $type)->where('ref', $ref)
                ->where('sent_on', '>', $today->subDays($days)->toDateString())->exists();
            if ($recent) continue;

            $seller->notify(new ForecastAlertNotification($type, $params, $settings['alerts_email']));
            DB::table('forecast_alert_log')->insert([
                'seller_id' => $sellerId, 'type' => $type, 'ref' => $ref, 'sent_on' => $today->toDateString(), 'created_at' => now(),
            ]);
            $sent++;
        }
        return $sent;
    }

    public function sendDigest(int $sellerId, array $shop): bool
    {
        $seller = User::find($sellerId);
        if (!$seller) return false;
        $shopName = DB::table('seller_applications')->where('user_id', $sellerId)->value('business_name') ?: $seller->name;
        $seller->notify(new ForecastDigestNotification($shop, $shopName));
        return true;
    }
}
