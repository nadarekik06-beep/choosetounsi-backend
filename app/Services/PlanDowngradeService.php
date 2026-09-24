<?php

namespace App\Services;

use App\Models\SellerApplication;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PlanDowngradeService
 *
 * Applies the side effects of a plan change on a seller's catalogue.
 *
 * Business rules:
 *   - Listings are NEVER deleted. Excess listings are soft-hidden
 *     (hidden_reason = 'over_plan_limit', is_active = false).
 *   - The seller's most recently created approved products up to the plan
 *     limit stay visible; older ones are hidden first.
 *   - On an upgrade (or a plan with a higher limit) hidden products are
 *     restored, most recent first, up to the new limit.
 *   - Promotions on hidden products are paused, not cancelled.
 *   - Sponsorships are paused when the new plan no longer includes them.
 *   - Analytics history and AI content already applied are never touched.
 */
class PlanDowngradeService
{
    /**
     * Apply all ripple effects for a move to $targetPlan (downgrade, expiry, admin change).
     */
    public function applyRippleEffects(SellerApplication $app, string $targetPlan): void
    {
        $sellerId = $app->user_id;
        $plan     = SubscriptionPlan::forSlug($targetPlan);

        Log::info("[PlanDowngradeService] Applying ripple effects for user #{$sellerId} → {$plan->slug}");

        try { $this->rebalanceProducts($sellerId, $plan->max_products); }
        catch (\Throwable $e) { Log::error("[PlanDowngradeService] rebalanceProducts failed: " . $e->getMessage()); }

        try {
            if (!$plan->hasFeature('sponsorships') || $plan->max_sponsored_products !== null) {
                $this->pauseSponsorships($sellerId, $plan->hasFeature('sponsorships') ? $plan->max_sponsored_products : 0);
            }
        } catch (\Throwable $e) { Log::error("[PlanDowngradeService] pauseSponsorships failed: " . $e->getMessage()); }

        try { $this->pausePromotions($sellerId); }
        catch (\Throwable $e) { Log::error("[PlanDowngradeService] pausePromotions failed: " . $e->getMessage()); }
    }

    /**
     * Effects of moving UP to a plan: restore hidden products (within the new
     * limit), paused sponsorships and paused promotions.
     */
    public function applyUpgradeEffects(int $sellerId, string $targetPlan): void
    {
        $plan = SubscriptionPlan::forSlug($targetPlan);

        $this->rebalanceProducts($sellerId, $plan->max_products);

        if ($plan->hasFeature('sponsorships')) {
            $this->resumeSponsorships($sellerId);
        }

        DB::table('promotions')
            ->where('seller_id', $sellerId)
            ->where('paused_reason', 'plan_downgrade')
            ->where('ends_at', '>', now())
            ->update(['status' => 'active', 'paused_reason' => null, 'paused_at' => null, 'updated_at' => now()]);
    }

    // ── Products ────────────────────────────────────────────────────────────

    /**
     * Make the number of visible approved products match $limit:
     * hide the oldest extras, or restore the most recent hidden ones.
     */
    public function rebalanceProducts(int $sellerId, ?int $limit): void
    {
        $visible = fn() => DB::table('products')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->whereNull('hidden_reason')
            ->where('is_approved', true);

        $hidden = fn() => DB::table('products')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->where('hidden_reason', 'over_plan_limit');

        if ($limit === null) {
            $hidden()->update(['hidden_reason' => null, 'is_active' => true, 'updated_at' => now()]);
            return;
        }

        $count = $visible()->count();

        if ($count > $limit) {
            $keepIds = $visible()->orderByDesc('created_at')->limit($limit)->pluck('id');
            $n = $visible()->whereNotIn('id', $keepIds)->update([
                'hidden_reason' => 'over_plan_limit',
                'is_active'     => false,
                'updated_at'    => now(),
            ]);
            Log::info("[PlanDowngradeService] Soft-hid {$n} products for user #{$sellerId} (limit {$limit})");
            return;
        }

        $capacity = $limit - $count;
        if ($capacity > 0) {
            $ids = $hidden()->orderByDesc('created_at')->limit($capacity)->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('products')->whereIn('id', $ids)
                    ->update(['hidden_reason' => null, 'is_active' => true, 'updated_at' => now()]);
                Log::info("[PlanDowngradeService] Restored {$ids->count()} hidden products for user #{$sellerId}");
            }
        }
    }

    // ── Sponsorships ────────────────────────────────────────────────────────

    /**
     * Pause active sponsorships beyond $keep (0 = pause all). Paused, never
     * deleted — resumeSponsorships() brings them back.
     */
    public function pauseSponsorships(int $sellerId, ?int $keep = 0): int
    {
        $query = DB::table('sponsorships')->where('seller_id', $sellerId)->where('status', 'active');

        if ($keep) {
            $keepIds = (clone $query)->orderByDesc('created_at')->limit($keep)->pluck('id');
            $query->whereNotIn('id', $keepIds);
        }

        $productIds = (clone $query)->pluck('product_id');
        $paused = $query->update([
            'status'        => 'expired',       // closest status to "paused" in the schema
            'paused_reason' => 'plan_downgrade',
            'paused_at'     => now(),
            'updated_at'    => now(),
        ]);

        if ($paused > 0) {
            DB::table('products')->whereIn('id', $productIds)
                ->update(['is_sponsored' => false, 'sponsored_priority' => 0, 'updated_at' => now()]);
        }

        Log::info("[PlanDowngradeService] Paused {$paused} sponsorships for user #{$sellerId}");
        return $paused;
    }

    public function resumeSponsorships(int $sellerId): int
    {
        $query = DB::table('sponsorships')
            ->where('seller_id', $sellerId)
            ->where('paused_reason', 'plan_downgrade')
            ->where(fn($q) => $q->whereNull('end_at')->orWhere('end_at', '>', now()));

        $productIds = (clone $query)->pluck('product_id');
        $n = $query->update(['status' => 'active', 'paused_reason' => null, 'paused_at' => null, 'updated_at' => now()]);

        if ($n > 0) {
            DB::table('products')->whereIn('id', $productIds)->update(['is_sponsored' => true, 'updated_at' => now()]);
        }
        return $n;
    }

    // ── Promotions ──────────────────────────────────────────────────────────

    /** Pause active promotions that include products now hidden by the plan limit. */
    private function pausePromotions(int $sellerId): void
    {
        $hiddenProductIds = DB::table('products')
            ->where('seller_id', $sellerId)
            ->where('hidden_reason', 'over_plan_limit')
            ->pluck('id');

        if ($hiddenProductIds->isEmpty()) return;

        $promotionIds = DB::table('promotion_products')
            ->whereIn('product_id', $hiddenProductIds)
            ->pluck('promotion_id')
            ->unique();

        if ($promotionIds->isEmpty()) return;

        $paused = DB::table('promotions')
            ->where('seller_id', $sellerId)
            ->whereIn('id', $promotionIds)
            ->where('status', 'active')
            ->update([
                'status'        => 'paused',
                'paused_reason' => 'plan_downgrade',
                'paused_at'     => now(),
                'updated_at'    => now(),
            ]);

        Log::info("[PlanDowngradeService] Paused {$paused} promotions for user #{$sellerId}");
    }

    public function countHiddenProducts(int $sellerId): int
    {
        return (int) DB::table('products')
            ->where('seller_id', $sellerId)
            ->where('hidden_reason', 'over_plan_limit')
            ->whereNull('deleted_at')
            ->count();
    }
}
