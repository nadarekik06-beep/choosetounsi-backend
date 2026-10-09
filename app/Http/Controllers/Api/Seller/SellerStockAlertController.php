<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\StockAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shop stock-alert settings (Paramètres de la boutique).
 *
 *   GET /api/seller/stock-alerts
 *   PUT /api/seller/stock-alerts  {enabled, threshold (1–50), channel: in_app|in_app_email}
 *
 * The threshold applies to every product without its own override
 * (products.low_stock_threshold). Out-of-stock alerts are always sent.
 */
class SellerStockAlertController extends Controller
{
    public const CHANNELS = ['in_app', 'in_app_email'];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($request->user())]);
    }

    public function update(Request $request, StockAlertService $alerts): JsonResponse
    {
        $data = $request->validate([
            'enabled'   => 'required|boolean',
            'threshold' => 'required|integer|min:1|max:50',
            'channel'   => ['required', Rule::in(self::CHANNELS)],
        ]);

        $seller = $request->user();
        $thresholdChanged = (int) $seller->stock_alert_threshold !== (int) $data['threshold'];

        $seller->update([
            'stock_alerts_enabled'  => (bool) $data['enabled'],
            'stock_alert_threshold' => (int) $data['threshold'],
            'stock_alert_channel'   => $data['channel'],
        ]);

        // New threshold: items already in the new low zone are flagged silently (no burst)
        if ($thresholdChanged) {
            $alerts->syncSeller($seller->fresh());
        }

        return response()->json([
            'success' => true,
            'message' => __('seller.stock_alerts.saved'),
            'data'    => $this->payload($seller->fresh()),
        ]);
    }

    private function payload($seller): array
    {
        return [
            'enabled'      => (bool) ($seller->stock_alerts_enabled ?? true),
            'threshold'    => (int) ($seller->stock_alert_threshold ?? config('stock.low_stock_threshold', 2)),
            'channel'      => $seller->stock_alert_channel ?: 'in_app',
            'has_email'    => filled($seller->email),
            'group_window' => (int) config('stock.alert_group_window_minutes', 10),
        ];
    }
}
