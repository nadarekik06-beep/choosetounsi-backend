<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\GrowthRadar\GrowthActions;
use App\Services\GrowthRadar\GrowthRadar;
use App\Services\GrowthRadar\Learning;
use App\Services\GrowthRadar\Presenter;
use App\Services\GrowthRadar\Results;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Growth Radar (seller dashboard). Everything is precomputed by growth:compute
 * (nightly) — these endpoints only read, except the first visit of a seller
 * that was never computed and the rate-limited refresh.
 *
 *   GET  /api/seller/growth-radar                      score + cards (+ results)
 *   POST /api/seller/growth-radar/refresh              recompute now (cooldown)
 *   POST /api/seller/growth-radar/cards/{id}/dismiss
 *   POST /api/seller/growth-radar/cards/{id}/snooze    {days: 3|7}
 *   POST /api/seller/growth-radar/cards/{id}/applied   {kind: edit|listing|bundle} (link actions)
 *   GET  /api/seller/growth-radar/history              past actions + what worked
 *
 * Plans without config('growth.full_feature') get the score and one locked card
 * (type, confidence, impact range only).
 */
class GrowthRadarController extends Controller
{
    public function __construct(private GrowthRadar $radar, private Presenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $sellerId = (int) $request->user()->id;
        if ($deny = $this->ensureSeller($sellerId)) return $deny;

        $week = GrowthRadar::weekStart();
        if (!DB::table('growth_snapshots')->where('seller_id', $sellerId)->exists()) {
            $this->radar->compute($sellerId, null, false);   // first visit: don't show an empty page
        }

        $full = $this->radar->hasFullFeed($sellerId);
        $snap = DB::table('growth_snapshots')->where('seller_id', $sellerId)->where('week_start', '<=', $week->toDateString())
            ->orderByDesc('week_start')->first();
        $prev = $snap ? DB::table('growth_snapshots')->where('seller_id', $sellerId)
            ->where('week_start', '<', $snap->week_start)->orderByDesc('week_start')->first() : null;

        $rows = DB::table('growth_cards')->where('seller_id', $sellerId)->where('status', 'new')
            ->orderByDesc('rank')->limit((int) config('growth.max_cards'))->get()->all();
        $locale = app()->getLocale();

        return response()->json(['success' => true, 'data' => [
            'access'      => $full ? 'full' : 'locked',
            'week_start'  => $week->toDateString(),
            'computed_at' => $snap ? CarbonImmutable::parse($snap->updated_at)->toIso8601String() : null,
            'score'       => $this->score($snap, $prev),
            'cards'       => $full ? $this->presenter->cards($rows, $locale) : array_map(fn ($r) => $this->presenter->locked($r), array_slice($rows, 0, 1)),
            'hidden_cards'=> $full ? 0 : max(0, count($rows) - 1),
            'results'     => $full ? app(Results::class)->recent($sellerId, $locale) : [],
            'snoozed'     => $full ? DB::table('growth_cards')->where('seller_id', $sellerId)->where('status', 'snoozed')->count() : 0,
        ]]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $sellerId = (int) $request->user()->id;
        if ($deny = $this->ensureSeller($sellerId)) return $deny;

        $minutes = (int) config('growth.refresh_cooldown_minutes');
        if (!Cache::add("growth_refresh:$sellerId", 1, now()->addMinutes($minutes))) {
            return response()->json(['success' => false, 'code' => 'COOLDOWN',
                'message' => __('growth.errors.refresh_cooldown', ['minutes' => $minutes])], 429);
        }
        $this->radar->compute($sellerId, null, false);
        return $this->index($request);
    }

    public function dismiss(Request $request, int $id): JsonResponse
    {
        $n = $this->cards($request)->where('id', $id)->whereIn('status', ['new', 'snoozed'])
            ->update(['status' => 'dismissed', 'dismissed_at' => now(), 'updated_at' => now()]);
        return response()->json(['success' => (bool) $n], $n ? 200 : 404);
    }

    public function snooze(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['days' => 'required|integer|in:' . implode(',', config('growth.snooze_days'))]);
        $n = $this->cards($request)->where('id', $id)->whereIn('status', ['new', 'snoozed'])
            ->update(['status' => 'snoozed', 'snoozed_until' => now()->addDays($data['days']), 'updated_at' => now()]);
        return response()->json(['success' => (bool) $n], $n ? 200 : 404);
    }

    /** Link actions (edit the listing, list a product, build a bundle) — promotions, coupons and boosts record themselves. */
    public function applied(Request $request, int $id, GrowthActions $actions): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:edit,listing,bundle']);
        $sellerId = (int) $request->user()->id;
        $card = $actions->card($sellerId, $id);
        if (!$card) return response()->json(['success' => false], 404);
        $days = (int) config('growth.results.edit_days');
        $actions->record($sellerId, $card, $data['kind'], null, $card->product_id ? (int) $card->product_id : null, now(), now()->addDays($days));
        return response()->json(['success' => true]);
    }

    public function history(Request $request): JsonResponse
    {
        $sellerId = (int) $request->user()->id;
        if ($deny = $this->ensureSeller($sellerId)) return $deny;
        if (!$this->radar->hasFullFeed($sellerId)) {
            return response()->json(['success' => true, 'data' => ['access' => 'locked', 'actions' => [], 'learning' => null]]);
        }
        return response()->json(['success' => true, 'data' => [
            'access'   => 'full',
            'actions'  => app(Results::class)->history($sellerId, app()->getLocale()),
            'learning' => Learning::forSeller($sellerId)->summary(),
        ]]);
    }

    private function cards(Request $request)
    {
        return DB::table('growth_cards')->where('seller_id', $request->user()->id);
    }

    private function score(?object $snap, ?object $prev): ?array
    {
        if (!$snap) return null;
        $subs = ['pricing', 'visibility', 'conversion', 'stock'];
        $delta = fn ($a, $b) => $a !== null && $b !== null ? (int) $a - (int) $b : null;
        $inputs = json_decode((string) $snap->inputs, true) ?: [];
        return [
            'value'    => $snap->score !== null ? (int) $snap->score : null,
            'trend'    => $prev ? $delta($snap->score, $prev->score) : null,
            'subs'     => array_map(fn ($k) => [
                'key' => $k, 'value' => $snap->$k !== null ? (int) $snap->$k : null, 'trend' => $prev ? $delta($snap->$k, $prev->$k) : null,
            ], $subs),
            'inputs'   => array_diff_key($inputs, ['unlocks' => 1]),
            'unlocks'  => $inputs['unlocks'] ?? [],
        ];
    }

    private function ensureSeller(int $sellerId): ?JsonResponse
    {
        $ok = DB::table('seller_applications')->where('user_id', $sellerId)->where('status', 'approved')->exists();
        return $ok ? null : response()->json(['success' => false, 'code' => 'NOT_SELLER', 'message' => __('seller.gate.not_seller')], 403);
    }
}
