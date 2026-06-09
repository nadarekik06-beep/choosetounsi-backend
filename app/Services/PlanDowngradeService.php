<?php
// app/Services/PlanDowngradeService.php

namespace App\Services;

use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PlanDowngradeService
 *
 * Applies all side effects when a seller's plan is downgraded.
 * Called by SubscriptionService after every downgrade event.
 *
 * Business rules:
 *   - Listings are NEVER deleted. Excess listings are soft-hidden (hidden_reason = 'over_plan_limit').
 *   - The seller's most recently created products up to the plan limit remain active.
 *   - Promotions beyond the downgrade date are paused, not cancelled. Budget is frozen.
 *   - Active sponsorships are paused, not cancelled. Seller can reactivate on re-upgrade.
 *   - Analytics historical data is NEVER deleted.
 *   - AI-generated content already applied to products stays applied.
 *   - Plan badge is removed when the new plan takes effect.
 */
class PlanDowngradeService
{
    /** Max products allowed per plan */
    private const PLAN_LIMITS = [
        'free'  => 30,
        'red'   => 150,
        'black' => null,  // unlimited
    ];

    /**
     * Apply all ripple effects for a downgrade to $targetPlan.
     * Called immediately for admin force / grace expiry,
     * or at end-of-cycle for scheduled downgrades.
     */
    public function applyRippleEffects(SellerApplication $app, string $targetPlan): void
    {
        $sellerId = $app->user_id;
        $limit    = self::PLAN_LIMITS[$targetPlan] ?? 30;

        Log::info("[PlanDowngradeService] Applying ripple effects for user #{$sellerId} → {$targetPlan}");

        try { $this->enforceProductLimit($sellerId, $limit); }
        catch (\Throwable $e) { Log::error("[PlanDowngradeService] enforceProductLimit failed: " . $e->getMessage()); }

        try { $this->pauseSponsorships($sellerId); }
        catch (\Throwable $e) { Log::error("[PlanDowngradeService] pauseSponsorships failed: " . $e->getMessage()); }

        try { $this->pausePromotions($sellerId, $targetPlan); }
        catch (\Throwable $e) { Log::error("[PlanDowngradeService] pausePromotions failed: " . $e->getMessage()); }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRODUCT LIMIT ENFORCEMENT
    // Soft-hide products that exceed the new plan limit.
    // Keeps the most recently created ones active.
    // ─────────────────────────────────────────────────────────────────────────

    private function enforceProductLimit(int $sellerId, ?int $limit): void
    {
        if ($limit === null) {
            // Unlimited plan (black) — unhide anything previously hidden
            DB::table('products')
                ->where('seller_id', $sellerId)
                ->where('hidden_reason', 'over_plan_limit')
                ->whereNull('deleted_at')
                ->update(['hidden_reason' => null, 'is_active' => true, 'updated_at' => now()]);
            return;
        }

        // Count currently active products (excluding soft-deleted)
        $activeCount = DB::table('products')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->whereNull('hidden_reason')   // don't count already-hidden
            ->where('is_approved', true)
            ->count();

        if ($activeCount <= $limit) return;

        // Select the IDs of products to KEEP (most recently created up to limit)
        $keepIds = DB::table('products')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->whereNull('hidden_reason')
            ->where('is_approved', true)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('id')
            ->toArray();

        // Soft-hide everything else
        $hiddenCount = DB::table('products')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->whereNull('hidden_reason')
            ->where('is_approved', true)
            ->whereNotIn('id', $keepIds)
            ->update([
                'hidden_reason' => 'over_plan_limit',
                'is_active'     => false,
                'updated_at'    => now(),
            ]);

        Log::info("[PlanDowngradeService] Soft-hidden {$hiddenCount} products for user #{$sellerId} (limit: {$limit})");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAUSE SPONSORSHIPS
    // Pause all active sponsorships (not cancel — seller can reactivate on upgrade)
    // ─────────────────────────────────────────────────────────────────────────

    private function pauseSponsorships(int $sellerId): void
    {
        $paused = DB::table('sponsorships')
            ->where('seller_id', $sellerId)
            ->where('status', 'active')
            ->update([
                'status'        => 'expired',       // closest status to "paused" in the schema
                'paused_reason' => 'plan_downgrade',
                'paused_at'     => now(),
                'updated_at'    => now(),
            ]);

        // Also clear is_sponsored flag on products so they don't appear boosted
        if ($paused > 0) {
            DB::table('products')
                ->where('seller_id', $sellerId)
                ->where('is_sponsored', true)
                ->update(['is_sponsored' => false, 'sponsored_priority' => 0, 'updated_at' => now()]);
        }

        Log::info("[PlanDowngradeService] Paused {$paused} sponsorships for user #{$sellerId}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAUSE PROMOTIONS
    // Active promotions are paused with the reason so seller knows why.
    // If downgrading to free: free plan still gets flash_sale and discount,
    // so we only pause if there's a specific feature gap.
    // For PFE scope: pause all active promotions on any downgrade — seller can
    // re-activate after confirming their new plan's limits.
    // ─────────────────────────────────────────────────────────────────────────

    private function pausePromotions(int $sellerId, string $targetPlan): void
    {
        // Free plan still supports flash_sale and discount types,
        // so we don't pause those on downgrade to free.
        // We pause promotions only if they are on products that got soft-hidden.
        $hiddenProductIds = DB::table('products')
            ->where('seller_id', $sellerId)
            ->where('hidden_reason', 'over_plan_limit')
            ->pluck('id');

        if ($hiddenProductIds->isEmpty()) return;

        // Pause promotions linked to hidden products
        $affectedPromotionIds = DB::table('promotion_products')
            ->whereIn('product_id', $hiddenProductIds)
            ->pluck('promotion_id')
            ->unique();

        if ($affectedPromotionIds->isEmpty()) return;

        $paused = DB::table('promotions')
            ->where('seller_id', $sellerId)
            ->whereIn('id', $affectedPromotionIds)
            ->where('status', 'active')
            ->update([
                'status'        => 'paused',
                'paused_reason' => 'plan_downgrade',
                'paused_at'     => now(),
                'updated_at'    => now(),
            ]);

        Log::info("[PlanDowngradeService] Paused {$paused} promotions for user #{$sellerId}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: count of hidden products (for seller notification)
    // ─────────────────────────────────────────────────────────────────────────

    public function countHiddenProducts(int $sellerId): int
    {
        return (int) DB::table('products')
            ->where('seller_id', $sellerId)
            ->where('hidden_reason', 'over_plan_limit')
            ->whereNull('deleted_at')
            ->count();
    }
}