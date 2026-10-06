<?php

namespace App\Services\GrowthRadar;

use App\Models\User;
use App\Notifications\Growth\GrowthRadarNotification;
use App\Services\Forecast\Calendar;
use App\Services\GrowthRadar\Detectors\DeadStockDetector;
use App\Services\GrowthRadar\Detectors\HiddenDemandDetector;
use App\Services\GrowthRadar\Detectors\LeakingProductDetector;
use App\Services\GrowthRadar\Detectors\PricePositionDetector;
use App\Services\GrowthRadar\Detectors\PromoTimingDetector;
use App\Services\GrowthRadar\Detectors\SeasonalDetector;
use App\Services\GrowthRadar\Detectors\WarmAudienceDetector;
use App\Services\PlanGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes one seller's Growth Radar: rebuild the sales series, run the
 * detectors, keep the best 3–7 cards, store the weekly score, notify.
 *
 * Cards keep their identity across days by fingerprint ("leak:42"): a daily
 * run refreshes the numbers of an open card, a dismissed or applied one does
 * not come back for config('growth.dismiss_memory_weeks'), a snoozed one
 * comes back when its snooze ends, and an open card no longer detected expires.
 */
class GrowthRadar
{
    /** Order matters: product cards claim their product (one product card per product). */
    private const DETECTORS = [
        PricePositionDetector::class, DeadStockDetector::class, LeakingProductDetector::class,
        WarmAudienceDetector::class, SeasonalDetector::class, HiddenDemandDetector::class, PromoTimingDetector::class,
    ];

    public function __construct(private PlanGate $gate, private Scorer $scorer, private Headlines $headlines) {}

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('growth.timezone'))->startOfDay();
    }

    public static function weekStart(?CarbonImmutable $today = null): CarbonImmutable
    {
        return ($today ?? self::today())->startOfWeek(CarbonImmutable::MONDAY);
    }

    public function hasFullFeed(int $sellerId): bool
    {
        return $this->gate->feature($sellerId, config('growth.full_feature')) === null;
    }

    /** Approved sellers (every plan gets a score; the full feed is gated at read time). */
    public function sellerIds(): array
    {
        return DB::table('seller_applications')->where('status', 'approved')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array{cards: int, new: int, score: ?int} */
    public function compute(int $sellerId, ?Benchmarks $bench = null, bool $notify = true, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $bench ??= new Benchmarks($today);
        $full = $this->hasFullFeed($sellerId);
        $ctx = SellerContext::load($sellerId, $today, $full ? 'full' : 'locked', Learning::forSeller($sellerId));

        $cards = [];
        foreach (self::DETECTORS as $class) {
            try {
                array_push($cards, ...app($class)->detect($ctx, $bench));
            } catch (\Throwable $e) {
                Log::error("[GrowthRadar] $class failed for seller $sellerId: " . $e->getMessage());
            }
        }
        usort($cards, fn (Card $a, Card $b) => $b->rank() <=> $a->rank());

        $week = self::weekStart($today);
        [$inserted, $kept] = DB::transaction(fn () => $this->persist($sellerId, $week, $cards));

        $score = $this->scorer->score($ctx, $bench);
        $this->storeSnapshot($sellerId, $week, $score);

        if ($full) {
            $locale = User::find($sellerId)?->preferredLocale() ?? 'fr';
            foreach (DB::table('growth_cards')->whereIn('id', $kept)->get() as $row) $this->headlines->fill($row, $locale);
            if ($notify && $inserted) $this->notifyNew($sellerId, $inserted);
        }
        return ['cards' => count($kept), 'new' => count($inserted), 'score' => $score['score']];
    }

    /**
     * @param Card[] $cards ranked best first
     * @return array{0: int[], 1: int[]} [inserted ids, all ids now in the feed]
     */
    private function persist(int $sellerId, CarbonImmutable $week, array $cards): array
    {
        $memory = now()->subWeeks((int) config('growth.dismiss_memory_weeks'));
        $blocked = DB::table('growth_cards')->where('seller_id', $sellerId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'dismissed')->where('dismissed_at', '>=', $memory))
                                 ->orWhere(fn ($q) => $q->where('status', 'applied')->where('applied_at', '>=', $memory)))
            ->pluck('fingerprint')->flip();
        // Open cards, plus this week's expired ones (a card can come back the same week)
        $open = DB::table('growth_cards')->where('seller_id', $sellerId)
            ->where(fn ($q) => $q->whereIn('status', ['new', 'snoozed'])
                                 ->orWhere(fn ($q) => $q->where('status', 'expired')->where('week_start', $week->toDateString())))
            ->orderBy('id')->get()->keyBy('fingerprint');   // latest row per fingerprint wins

        $max = (int) config('growth.max_cards');
        $inserted = $kept = [];
        $seen = [];
        foreach ($cards as $card) {
            if (count($seen) >= $max) break;
            if (isset($blocked[$card->fingerprint]) || isset($seen[$card->fingerprint])) continue;
            $seen[$card->fingerprint] = true;

            $values = [
                'type' => $card->type, 'product_id' => $card->productId, 'confidence' => $card->confidence,
                'impact_low' => $card->impactLow, 'impact_high' => $card->impactHigh, 'rank' => $card->rank(),
                'payload' => json_encode($card->payload(), JSON_UNESCAPED_UNICODE), 'week_start' => $week->toDateString(),
                'updated_at' => now(),
            ];
            if ($row = $open[$card->fingerprint] ?? null) {
                $reopen = $row->status === 'expired'
                    || ($row->status === 'snoozed' && $row->snoozed_until && CarbonImmutable::parse($row->snoozed_until)->isPast());
                $textChanged = $row->payload !== $values['payload'];
                DB::table('growth_cards')->where('id', $row->id)->update($values
                    + ($reopen ? ['status' => 'new', 'snoozed_until' => null] : [])
                    + ($textChanged ? ['headlines' => null] : []));
                $kept[] = (int) $row->id;
            } else {
                $id = DB::table('growth_cards')->insertGetId($values + [
                    'seller_id' => $sellerId, 'fingerprint' => $card->fingerprint, 'status' => 'new', 'created_at' => now(),
                ]);
                $inserted[] = $id;
                $kept[] = $id;
            }
        }

        // Open cards that were not found again are no longer true
        DB::table('growth_cards')->where('seller_id', $sellerId)
            ->where(fn ($q) => $q->where('status', 'new')
                                 ->orWhere(fn ($q) => $q->where('status', 'snoozed')->where('snoozed_until', '<', now())))
            ->whereNotIn('id', $kept ?: [0])->update(['status' => 'expired', 'updated_at' => now()]);

        return [$inserted, $kept];
    }

    private function storeSnapshot(int $sellerId, CarbonImmutable $week, array $s): void
    {
        DB::table('growth_snapshots')->updateOrInsert(
            ['seller_id' => $sellerId, 'week_start' => $week->toDateString()],
            ['score' => $s['score'], 'pricing' => $s['pricing'], 'visibility' => $s['visibility'], 'conversion' => $s['conversion'],
             'stock' => $s['stock'], 'inputs' => json_encode($s['inputs']), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** Bell for new high-impact, not-low-confidence cards; e-mail at most once a week. */
    private function notifyNew(int $sellerId, array $ids): void
    {
        $min = (int) config('growth.notify.min_impact_high');
        $rows = DB::table('growth_cards')->whereIn('id', $ids)->where('confidence', '!=', 'low')
            ->where('impact_high', '>=', $min)->orderByDesc('rank')->get();
        if ($rows->isEmpty()) return;

        $seller = User::find($sellerId);
        if (!$seller) return;
        $gap = now()->subDays((int) config('growth.notify.email_gap_days'));
        $emailedRecently = DB::table('growth_cards')->where('seller_id', $sellerId)
            ->where('notified_at', '>=', $gap)->exists();

        $top = $rows->first();
        $payload = json_decode($top->payload, true);
        try {
            $seller->notify(new GrowthRadarNotification('new_cards', [
                'count' => $rows->count(), 'high' => (int) $rows->max('impact_high'), 'type' => $top->type,
                'params' => $payload['params'] ?? [], 'action_kind' => $payload['action']['kind'] ?? null,
            ], !$emailedRecently));
            DB::table('growth_cards')->whereIn('id', $rows->pluck('id'))->update(['notified_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[GrowthRadar] Notify failed: ' . $e->getMessage(), ['seller' => $sellerId]);
        }
    }

    /** Make sure the calendar has this year's and next year's moments (idempotent). */
    public static function ensureCalendar(CarbonImmutable $today): void
    {
        foreach ([(int) $today->format('Y'), (int) $today->format('Y') + 1] as $y) {
            Calendar::seedHijriYear($y);
            Calendar::seedFixedYear($y);
        }
    }
}
