<?php

namespace App\Services\Ads;

use App\Exceptions\Ads\AdRuleViolation;
use App\Jobs\GenerateAdCopy;
use App\Models\AdWalletTransaction as Tx;
use App\Models\Product;
use App\Models\Sponsorship;
use App\Models\User;
use App\Notifications\Ads\CampaignActivated;
use App\Notifications\Ads\CampaignEnded;
use App\Notifications\Ads\CampaignPaused;
use App\Services\PlanGate;
use App\Support\Wilayas;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The campaign engine's single writer: create / edit / pause / resume / cancel /
 * complete / reject, with the business rules that go with each step.
 *
 *   draft → active ⇄ paused → completed
 *               ↘ cancelled (seller/admin)     open → rejected (admin, charges refunded)
 *
 * CPC campaigns take nothing upfront: clicks are charged from the ad wallet as
 * they happen, so ending a campaign has no reserve to give back. A rejection
 * refunds everything the campaign was charged.
 */
class SponsorshipService
{
    /** Pause reasons the seller hears about (manual pauses and the daily cap are silent). */
    const NOTIFY_PAUSE = [
        Sponsorship::PAUSE_WALLET_EMPTY, Sponsorship::PAUSE_OUT_OF_STOCK, Sponsorship::PAUSE_PRODUCT_INACTIVE,
        Sponsorship::PAUSE_PLAN_DOWNGRADE, Sponsorship::PAUSE_ADMIN,
    ];

    /** Pauses the platform lifts by itself once the cause is gone. */
    const AUTO_RESUMABLE = [
        Sponsorship::PAUSE_BUDGET_TODAY, Sponsorship::PAUSE_WALLET_EMPTY,
        Sponsorship::PAUSE_OUT_OF_STOCK, Sponsorship::PAUSE_PRODUCT_INACTIVE,
    ];

    const TARGETING = ['target_gender', 'target_wilaya_ids', 'target_category_ids', 'target_price_min', 'target_price_max'];

    public function __construct(
        private AdSettings $settings,
        private AdPricing $pricing,
        private AdWalletService $wallets,
        private ReadinessService $readiness,
        private PlanGate $gate,
    ) {}

    // ── Create ──────────────────────────────────────────────────────────────

    /**
     * @param array{product_id: int, daily_budget: float, max_cpc?: ?float, total_budget?: ?float,
     *              end_date?: ?string, placements?: ?array, goal?: ?string, target_gender?: ?string,
     *              target_wilaya_ids?: ?array, target_category_ids?: ?array,
     *              target_price_min?: ?float, target_price_max?: ?float} $data
     */
    public function create(User $seller, array $data): Sponsorship
    {
        $this->assertFeature($seller->id);

        $product = Product::where('id', $data['product_id'])->where('seller_id', $seller->id)->first();
        if (!$product) {
            throw AdRuleViolation::make('product_not_found', [], [], 404);
        }

        $readiness = $this->readiness->check($product);
        if (!$readiness['passes']) {
            throw AdRuleViolation::make('not_ready', ['readiness' => $readiness]);
        }

        $maxCpc = isset($data['max_cpc']) ? (float) $data['max_cpc'] : $this->pricing->suggestedCpc($product->category_id);
        $attrs  = $this->budgetAttributes($product, (float) $data['daily_budget'], $maxCpc, $data['total_budget'] ?? null)
                + $this->scheduleAttributes($data['end_date'] ?? null)
                + $this->targetingAttributes($data)
                + ['placements' => $this->placements($data['placements'] ?? null)];

        $this->assertWalletCovers($seller->id, (float) $attrs['daily_budget']);

        $campaign = $this->withOpenGuard(fn () => DB::transaction(function () use ($seller, $product, $attrs, $readiness, $data) {
            // Serialize campaign creation per product, then re-check inside the lock.
            Product::whereKey($product->id)->lockForUpdate()->first();
            if (Sponsorship::hasOpenForProduct($product->id)) {
                throw AdRuleViolation::make('already_open');
            }
            if ($deny = $this->gate->canSponsor($seller->id)) {
                throw new HttpResponseException($deny);
            }

            $copy = GenerateAdCopy::fallback($product);
            $campaign = Sponsorship::create($attrs + [
                'seller_id'       => $seller->id,
                'product_id'      => $product->id,
                'plan_type'       => $this->gate->tierFor($seller->id),
                'pricing_model'   => Sponsorship::PRICING_CPC,
                'goal'            => $data['goal'] ?? 'sales',
                'status'          => Sponsorship::STATUS_ACTIVE,
                'start_at'        => now(),
                'readiness_score' => $readiness['score'],
                'ai_ad_copy'      => $copy['ad_copy'],
                'ai_tags'         => $copy['tags'],
            ]);
            Sponsorship::syncProductFlags($product->id);
            AdServer::flushEligible();
            return $campaign;
        }));

        GenerateAdCopy::dispatch($campaign->id);
        $seller->notify(new CampaignActivated($campaign->load('product')));

        return $campaign;
    }

    /**
     * Rows of the legacy prepaid-per-day flow (the old promote page), kept until the
     * new dashboard replaces it: same one-open-campaign-per-product guard, queued AI copy.
     */
    public function createLegacy(array $attrs): Sponsorship
    {
        $campaign = $this->withOpenGuard(fn () => DB::transaction(function () use ($attrs) {
            Product::whereKey($attrs['product_id'])->lockForUpdate()->first();
            if (Sponsorship::hasOpenForProduct($attrs['product_id'])) {
                throw AdRuleViolation::make('already_open');
            }
            $campaign = Sponsorship::create($attrs + [
                'pricing_model' => Sponsorship::PRICING_LEGACY,
                'status'        => Sponsorship::STATUS_ACTIVE,
                'max_cpc'       => $this->settings->float('min_cpc'),
            ]);
            Sponsorship::syncProductFlags($attrs['product_id']);
            AdServer::flushEligible();
            return $campaign;
        }));

        GenerateAdCopy::dispatch($campaign->id);
        return $campaign;
    }

    // ── Edit ────────────────────────────────────────────────────────────────

    /** Budget, bid, end date, total budget, placements and targeting of an open campaign. */
    public function update(Sponsorship $campaign, array $data): Sponsorship
    {
        return DB::transaction(function () use ($campaign, $data) {
            $c = $this->locked($campaign);
            if (!$c->isOpen()) {
                throw AdRuleViolation::make('not_open');
            }

            $attrs = [];
            if (array_key_exists('daily_budget', $data) || array_key_exists('max_cpc', $data) || array_key_exists('total_budget', $data)) {
                $attrs += $this->budgetAttributes(
                    $c->product,
                    (float) ($data['daily_budget'] ?? $c->daily_budget),
                    (float) ($data['max_cpc'] ?? $c->max_cpc),
                    array_key_exists('total_budget', $data) ? $data['total_budget'] : $c->total_budget,
                );
            }
            if (array_key_exists('end_date', $data)) {
                $attrs += $this->scheduleAttributes($data['end_date']);
            }
            if (array_key_exists('placements', $data)) {
                $attrs['placements'] = $this->placements($data['placements']);
            }
            if (array_intersect(array_keys($data), self::TARGETING)) {
                $attrs += $this->targetingAttributes($data + $c->only(self::TARGETING));
            }

            $c->update($attrs);
            AdServer::flushEligible();
            return $c;
        });
    }

    // ── Pause / resume ──────────────────────────────────────────────────────

    public function pause(Sponsorship $campaign, string $reason = Sponsorship::PAUSE_MANUAL): Sponsorship
    {
        $c = DB::transaction(function () use ($campaign, $reason) {
            $c = $this->locked($campaign);
            if ($c->status !== Sponsorship::STATUS_ACTIVE) {
                throw AdRuleViolation::make('not_active');
            }
            $c->update(['status' => Sponsorship::STATUS_PAUSED, 'paused_reason' => $reason, 'paused_at' => now()]);
            Sponsorship::syncProductFlags($c->product_id);
            AdServer::flushEligible();
            return $c;
        });

        if (in_array($reason, self::NOTIFY_PAUSE, true)) {
            $c->seller?->notify(new CampaignPaused($c->load('product'), $reason));
        }
        return $c;
    }

    /**
     * Resume a paused campaign. Sellers can resume their own pauses and the
     * automatic ones once the cause is fixed; admin pauses need an admin, plan
     * pauses come back with the plan (resumeForPlan). A campaign whose end date
     * passed while paused completes instead.
     */
    public function resume(Sponsorship $campaign, bool $byAdmin = false): Sponsorship
    {
        $c = $campaign->fresh();
        if ($c->status !== Sponsorship::STATUS_PAUSED) {
            throw AdRuleViolation::make('not_paused');
        }
        if ($c->end_at !== null && $c->end_at->isPast()) {
            return $this->complete($c);
        }
        if (!$byAdmin) {
            if ($c->paused_reason === Sponsorship::PAUSE_ADMIN) {
                throw AdRuleViolation::make('paused_by_admin', [], [], 403);
            }
            if ($c->paused_reason === Sponsorship::PAUSE_PLAN_DOWNGRADE) {
                throw AdRuleViolation::make('paused_by_plan', [], [], 403);
            }
            $this->assertFeature($c->seller_id);
        }
        if ($c->isCpc() && $c->spent_today_date?->toDateString() === AdClock::today()
            && (float) $c->spent_today >= (float) $c->daily_budget) {
            throw AdRuleViolation::make('budget_exhausted_today');
        }
        $this->assertProductServable($c->product);
        if ($c->isCpc()) {
            $this->assertWalletCovers($c->seller_id, (float) $c->daily_budget);
        }

        return $this->activate($c);
    }

    /**
     * Scheduled resume of an automatic pause (daily budget reset, restock, wallet top-up):
     * the product must be sellable again and the wallet able to pay for a click.
     * Returns null when the cause is still there.
     */
    public function resumeAutomatically(Sponsorship $campaign): ?Sponsorship
    {
        $c = $campaign->fresh(['product']);
        if ($c->status !== Sponsorship::STATUS_PAUSED || !in_array($c->paused_reason, self::AUTO_RESUMABLE, true)) {
            return null;
        }
        if ($c->end_at !== null && $c->end_at->isPast()) {
            return $this->complete($c);
        }
        if (!$c->product || !$c->product->is_approved || !$c->product->is_active || $c->product->trashed()
            || $this->readiness->totalStock($c->product) <= 0) {
            return null;
        }
        if ($c->isCpc()) {
            if ($c->spent_today_date?->toDateString() === AdClock::today() && (float) $c->spent_today >= (float) $c->daily_budget) {
                return null;
            }
            if ($this->wallets->available($c->seller_id) < $this->pricing->floorCpc($c->product->category_id)) {
                return null;
            }
        }
        return $this->activate($c);
    }

    /** A paused campaign's cause changed (e.g. the budget cap lifted but the wallet is empty): relabel it and tell the seller. */
    public function repause(Sponsorship $campaign, string $reason): Sponsorship
    {
        $c = DB::transaction(function () use ($campaign, $reason) {
            $c = $this->locked($campaign);
            if ($c->status !== Sponsorship::STATUS_PAUSED) {
                throw AdRuleViolation::make('not_paused');
            }
            $c->update(['paused_reason' => $reason, 'paused_at' => now()]);
            return $c;
        });
        if (in_array($reason, self::NOTIFY_PAUSE, true)) {
            $c->seller?->notify(new CampaignPaused($c->load('product'), $reason));
        }
        return $c;
    }

    // ── End ─────────────────────────────────────────────────────────────────

    /** Seller or admin stops a campaign for good. Nothing is reserved, so nothing to refund. */
    public function cancel(Sponsorship $campaign, ?User $admin = null): Sponsorship
    {
        $c = $this->end($campaign, Sponsorship::STATUS_CANCELLED);
        if ($admin) {
            $c->seller?->notify(new CampaignEnded($c->load('product'), Sponsorship::STATUS_CANCELLED, $this->summary($c)));
        }
        return $c;
    }

    /** End date reached (or total budget spent). */
    public function complete(Sponsorship $campaign): Sponsorship
    {
        $c = $this->end($campaign, Sponsorship::STATUS_COMPLETED);
        $c->seller?->notify(new CampaignEnded($c->load('product'), Sponsorship::STATUS_COMPLETED, $this->summary($c)));
        return $c;
    }

    /** Admin post-moderation: the campaign ends and every charge it caused is refunded. */
    public function reject(Sponsorship $campaign, string $reason, User $admin): Sponsorship
    {
        $c = $this->end($campaign, Sponsorship::STATUS_REJECTED, ['rejection_reason' => mb_substr($reason, 0, 255)]);
        $tx = $this->wallets->refundCampaignCharges($c, 'rejected', $admin->id);

        $summary = $this->summary($c) + ['refund' => $tx ? (float) $tx->amount : 0.0];
        $c->seller?->notify(new CampaignEnded($c->load('product'), Sponsorship::STATUS_REJECTED, $summary));
        return $c;
    }

    /** Scheduled (ads:complete-ended): open campaigns past their end date complete. */
    public function completeEnded(): int
    {
        $count = 0;
        Sponsorship::open()->whereNotNull('end_at')->where('end_at', '<=', now())
            ->orderBy('id')->each(function (Sponsorship $c) use (&$count) {
                try {
                    $this->complete($c);
                    $count++;
                } catch (AdRuleViolation $e) {
                    // raced with another transition — nothing to do
                } catch (\Throwable $e) {
                    Log::error('[ads:complete-ended] campaign #' . $c->id . ': ' . $e->getMessage());
                }
            });
        return $count;
    }

    // ── Plan changes ────────────────────────────────────────────────────────

    /** Pause active campaigns beyond the $keep most recent (0 = all) after a downgrade. */
    public function pauseForPlan(int $sellerId, int $keep = 0): int
    {
        $ids = Sponsorship::where('seller_id', $sellerId)->where('status', Sponsorship::STATUS_ACTIVE)
            ->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->slice($keep);

        $paused = 0;
        foreach (Sponsorship::whereIn('id', $ids)->get() as $c) {
            $this->pause($c, Sponsorship::PAUSE_PLAN_DOWNGRADE);
            $paused++;
        }
        return $paused;
    }

    /** The plan allows sponsoring again: plan-paused campaigns resume (or complete if ended). */
    public function resumeForPlan(int $sellerId): int
    {
        $resumed = 0;
        $campaigns = Sponsorship::where('seller_id', $sellerId)->where('status', Sponsorship::STATUS_PAUSED)
            ->where('paused_reason', Sponsorship::PAUSE_PLAN_DOWNGRADE)->get();

        foreach ($campaigns as $c) {
            if ($c->end_at !== null && $c->end_at->isPast()) {
                $this->complete($c);
                continue;
            }
            $this->activate($c);
            $resumed++;
        }
        return $resumed;
    }

    // ── Reporting ───────────────────────────────────────────────────────────

    /** @return array{spend: float, paid_spend: float, credit_spend: float, clicks: int, impressions: int, orders: int, revenue: float, roas: ?float, cost_per_order: ?float} */
    public function summary(Sponsorship $c): array
    {
        $ledger = Tx::where('sponsorship_id', $c->id)->whereIn('type', [Tx::TYPE_CLICK_CHARGE, Tx::TYPE_REFUND])
            ->selectRaw('SUM(amount) AS total, SUM(credit_amount) AS credit')->first();

        $spend  = round(-(float) ($ledger->total ?? 0), 3);
        $credit = round(-(float) ($ledger->credit ?? 0), 3);
        $revenue = (float) $c->attributed_revenue;
        $orders  = (int) $c->attributed_orders;

        return [
            'spend'          => $spend,
            'paid_spend'     => round($spend - $credit, 3),
            'credit_spend'   => $credit,
            'clicks'         => (int) $c->clicks,
            'impressions'    => (int) $c->impressions,
            'orders'         => $orders,
            'revenue'        => round($revenue, 3),
            'roas'           => $spend > 0 ? round($revenue / $spend, 2) : null,
            'cost_per_order' => $orders > 0 ? round($spend / $orders, 3) : null,
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function activate(Sponsorship $campaign): Sponsorship
    {
        return DB::transaction(function () use ($campaign) {
            $c = $this->locked($campaign);
            if ($c->status !== Sponsorship::STATUS_PAUSED) {
                throw AdRuleViolation::make('not_paused');
            }
            $c->update(['status' => Sponsorship::STATUS_ACTIVE, 'paused_reason' => null, 'paused_at' => null]);
            Sponsorship::syncProductFlags($c->product_id);
            AdServer::flushEligible();
            return $c;
        });
    }

    private function end(Sponsorship $campaign, string $status, array $extra = []): Sponsorship
    {
        return DB::transaction(function () use ($campaign, $status, $extra) {
            $c = $this->locked($campaign);
            if (!$c->isOpen()) {
                throw AdRuleViolation::make('not_open');
            }
            $c->update(['status' => $status, 'ended_at' => now(), 'paused_reason' => null] + $extra);
            Sponsorship::syncProductFlags($c->product_id);
            AdServer::flushEligible();
            return $c;
        });
    }

    private function locked(Sponsorship $campaign): Sponsorship
    {
        return Sponsorship::whereKey($campaign->id)->with('product')->lockForUpdate()->firstOrFail();
    }

    /** Turn a lost race on the one-open-campaign-per-product UNIQUE into the usual error. */
    private function withOpenGuard(callable $fn): Sponsorship
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'uq_sponsorships_open_product')) {
                throw AdRuleViolation::make('already_open');
            }
            throw $e;
        }
    }

    private function assertFeature(int $sellerId): void
    {
        if ($deny = $this->gate->feature($sellerId, 'sponsorships')) {
            throw new HttpResponseException($deny);
        }
    }

    private function assertWalletCovers(int $sellerId, float $amount): void
    {
        $available = $this->wallets->available($sellerId);
        if ($available + 0.0005 < $amount) {
            throw \App\Exceptions\Ads\InsufficientAdFunds::for($amount, $available);
        }
    }

    private function assertProductServable(?Product $product): void
    {
        if (!$product || !$product->is_approved || !$product->is_active || $product->trashed()
            || $this->readiness->totalStock($product) <= 0) {
            throw AdRuleViolation::make('product_unavailable');
        }
    }

    private function budgetAttributes(Product $product, float $daily, float $maxCpc, $total): array
    {
        $floor     = $this->pricing->floorCpc($product->category_id);
        $minBudget = $this->settings->float('min_daily_budget');
        $daily     = round($daily, 3);
        $maxCpc    = round($maxCpc, 3);

        if ($maxCpc + 0.0005 < $floor) {
            throw AdRuleViolation::make('cpc_below_min', ['min' => $floor], ['min' => number_format($floor, 3)]);
        }
        if ($daily + 0.0005 < $minBudget) {
            throw AdRuleViolation::make('budget_below_min', ['min' => $minBudget], ['min' => number_format($minBudget, 3)]);
        }
        if ($daily + 0.0005 < $maxCpc) {
            throw AdRuleViolation::make('budget_below_cpc', ['min' => $maxCpc], ['min' => number_format($maxCpc, 3)]);
        }
        if ($total !== null && (float) $total + 0.0005 < $daily) {
            throw AdRuleViolation::make('total_below_daily');
        }

        return [
            'daily_budget' => $daily,
            'max_cpc'      => $maxCpc,
            'total_budget' => $total !== null ? round((float) $total, 3) : null,
        ];
    }

    /** end_date is an Africa/Tunis calendar day (last day included); null = until stopped. */
    private function scheduleAttributes(?string $endDate): array
    {
        if ($endDate === null || $endDate === '') {
            return ['end_at' => null];
        }
        $end = Carbon::parse($endDate, AdClock::timezone())->endOfDay();
        if ($end->isPast()) {
            throw AdRuleViolation::make('invalid_end_date');
        }
        return ['end_at' => AdClock::toStorage($end)];
    }

    private function placements(?array $placements): ?array
    {
        if (empty($placements)) {
            return null;
        }
        $placements = array_values(array_unique($placements));
        if (array_diff($placements, Sponsorship::PLACEMENTS)) {
            throw AdRuleViolation::make('invalid_placements');
        }
        // Every placement selected = no restriction.
        return count($placements) === count(Sponsorship::PLACEMENTS) ? null : $placements;
    }

    private function targetingAttributes(array $data): array
    {
        $min = $data['target_price_min'] ?? null;
        $max = $data['target_price_max'] ?? null;
        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            throw AdRuleViolation::make('invalid_price_range');
        }

        return [
            'target_gender'       => $data['target_gender'] ?? null,
            'target_wilaya_ids'   => Wilayas::normalizeMany($data['target_wilaya_ids'] ?? null) ?: null,
            'target_category_ids' => array_values(array_map('intval', (array) ($data['target_category_ids'] ?? []))) ?: null,
            'target_price_min'    => $data['target_price_min'] ?? null,
            'target_price_max'    => $data['target_price_max'] ?? null,
        ];
    }
}
