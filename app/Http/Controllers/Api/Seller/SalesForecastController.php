<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\Forecast\AiNarrator;
use App\Services\Forecast\ForecastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Seller dashboard → Outils IA → Ventes.
 *
 * Reads stored snapshots (computed nightly and after confirmed orders). The only
 * synchronous computation is the first visit, a stale snapshot (scheduler not
 * running) and "Actualiser", which is limited to once per 10 minutes.
 */
class SalesForecastController extends Controller
{
    public function __construct(private ForecastService $service) {}

    /** GET /seller/forecast?product_id= (none = whole shop) */
    public function show(Request $request)
    {
        $request->validate(['product_id' => 'nullable|integer|min:1']);
        $sellerId  = (int) auth()->id();
        $productId = (int) $request->query('product_id', 0);
        if ($productId && !$this->owns($sellerId, $productId)) {
            return response()->json(['success' => false, 'message' => __('seller.common.product_not_found')], 404);
        }

        $shop = $this->service->latest($sellerId, 0);
        $stale = !$shop || $shop['snapshot_date'] < $this->service->today()->subDays(1)->toDateString();
        if ($stale) $this->service->computeSeller($sellerId);

        return $this->respond($sellerId, $productId);
    }

    /** POST /seller/forecast/refresh — manual recompute, once per 10 min per seller */
    public function refresh(Request $request)
    {
        $request->validate(['product_id' => 'nullable|integer|min:1']);
        $sellerId  = (int) auth()->id();
        $productId = (int) $request->input('product_id', 0);
        if ($productId && !$this->owns($sellerId, $productId)) {
            return response()->json(['success' => false, 'message' => __('seller.common.product_not_found')], 404);
        }

        $key = "forecast-refresh:$sellerId";
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            return response()->json([
                'success' => false, 'code' => 'REFRESH_COOLDOWN',
                'message' => __('forecast.refresh_wait', ['minutes' => $minutes]),
                'retry_in' => RateLimiter::availableIn($key),
            ], 429);
        }
        RateLimiter::hit($key, 60 * (int) config('forecast.refresh_cooldown_minutes'));

        $this->service->computeSeller($sellerId);
        return $this->respond($sellerId, $productId);
    }

    /** GET /seller/forecast/explain?product_id=&locale= — cached Groq text or template */
    public function explain(Request $request, AiNarrator $narrator)
    {
        $request->validate(['product_id' => 'nullable|integer|min:1', 'locale' => 'nullable|in:fr,en,ar']);
        $sellerId  = (int) auth()->id();
        $productId = (int) $request->query('product_id', 0);
        if ($productId && !$this->owns($sellerId, $productId)) {
            return response()->json(['success' => false, 'message' => __('seller.common.product_not_found')], 404);
        }
        $payload = $this->service->latest($sellerId, $productId);
        if (!$payload) return response()->json(['success' => true, 'data' => null]);

        $locale = $request->query('locale', app()->getLocale());
        return response()->json(['success' => true, 'data' => $narrator->explain($sellerId, $productId, $payload, $locale)]);
    }

    /** PUT /seller/forecast/settings — shop defaults, or one product's lead time / safety days */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'product_id'          => 'nullable|integer|min:1',
            'lead_time_days'      => 'nullable|integer|min:0|max:180',
            'safety_days'         => 'nullable|integer|min:0|max:90',
            'alerts_enabled'      => 'sometimes|boolean',
            'alerts_email'        => 'sometimes|boolean',
            'weekly_digest'       => 'sometimes|boolean',
            'stockout_alert_days' => 'sometimes|integer|min:1|max:60',
        ]);
        $sellerId  = (int) auth()->id();
        $productId = $data['product_id'] ?? null;
        if ($productId && !$this->owns($sellerId, $productId)) {
            return response()->json(['success' => false, 'message' => __('seller.common.product_not_found')], 404);
        }
        unset($data['product_id']);
        if (!$productId) {
            // Shop defaults can't be "unset"
            foreach (['lead_time_days', 'safety_days'] as $k) if (array_key_exists($k, $data) && $data[$k] === null) unset($data[$k]);
        }
        $this->service->saveSettings($sellerId, $data, $productId);

        // Lead time and safety margin change reorder dates: recompute right away.
        if (array_key_exists('lead_time_days', $data) || array_key_exists('safety_days', $data)) {
            $this->service->computeSeller($sellerId);
        }
        return $this->respond($sellerId, (int) ($productId ?? $request->input('view_product_id', 0)));
    }

    private function respond(int $sellerId, int $productId)
    {
        $settings = $this->service->settings($sellerId);
        $key = "forecast-refresh:$sellerId";
        return response()->json(['success' => true, 'data' => [
            'forecast'  => $this->service->latest($sellerId, $productId),
            'products'  => $this->service->productList($sellerId),
            'track_record' => $this->service->accuracySummary($sellerId),
            'settings'  => [
                'lead_time_days'      => $settings['lead_time_days'],
                'safety_days'         => $settings['safety_days'],
                'alerts_enabled'      => $settings['alerts_enabled'],
                'alerts_email'        => $settings['alerts_email'],
                'weekly_digest'       => $settings['weekly_digest'],
                'stockout_alert_days' => $settings['stockout_alert_days'],
                'product'             => $productId ? ($settings['products'][$productId] ?? (object) []) : null,
            ],
            'refresh_in' => RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0,
        ]]);
    }

    private function owns(int $sellerId, int $productId): bool
    {
        return DB::table('products')->where('id', $productId)->where('seller_id', $sellerId)->whereNull('deleted_at')->exists();
    }
}
