<?php

namespace App\Services\Sellers;

use App\Models\SellerApplication;
use App\Models\SubscriptionPlan;
use App\Services\Ads\AdServer;
use App\Services\Catalog\SubcategoryBar;
use App\Services\PlanDowngradeService;
use App\Services\Recommendation\RelatedProducts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A suspended seller (users.is_active = 0) has no product on the storefront.
 *
 * Same mechanism as plan limits: live products get is_active = false and
 * hidden_reason = 'seller_suspended'. Every storefront query already requires
 * products.is_active, so listings, search, menus, home, ads, recommendations and
 * the product page all follow without per-page conditions.
 *
 * Nothing is deleted. Products the seller had switched off themselves, or that are
 * hidden for the plan limit, keep their state. Reactivation restores exactly the
 * products this class hid, then re-applies the seller's plan limit.
 * Orders, order history, the seller dashboard and the admin panel do not filter
 * on is_active, so they keep showing these products.
 */
class SuspendedSellerProducts
{
    public const REASON = 'seller_suspended';

    /** Hide the live products of $sellerId. Returns how many were hidden. */
    public function hide(int $sellerId): int
    {
        $n = $this->visible($sellerId)->update([
            'is_active' => false, 'hidden_reason' => self::REASON, 'updated_at' => now(),
        ]);

        Log::info("[SuspendedSellerProducts] Hid {$n} products of suspended seller #{$sellerId}");
        $this->flushCaches();

        return $n;
    }

    /** Bring back what hide() hid, within the seller's plan limit. Returns how many were restored. */
    public function restore(int $sellerId): int
    {
        $n = $this->hidden($sellerId)->update([
            'is_active' => true, 'hidden_reason' => null, 'updated_at' => now(),
        ]);

        if ($n > 0) {
            $plan = SellerApplication::where('user_id', $sellerId)->where('status', 'approved')->latest()->value('plan');
            try {
                app(PlanDowngradeService::class)->rebalanceProducts($sellerId, SubscriptionPlan::forSlug($plan ?? 'free')->max_products);
            } catch (\Throwable $e) {
                Log::error("[SuspendedSellerProducts] Plan limit check failed for seller #{$sellerId}: " . $e->getMessage());
            }
        }

        Log::info("[SuspendedSellerProducts] Restored {$n} products of reactivated seller #{$sellerId}");
        $this->flushCaches();

        return $n;
    }

    /** Live products of a seller (what hide() would touch). */
    public function visible(int $sellerId)
    {
        return DB::table('products')->where('seller_id', $sellerId)
            ->where('is_active', true)->whereNull('hidden_reason')->whereNull('deleted_at');
    }

    /** Products hidden because the seller is suspended. */
    public function hidden(int $sellerId)
    {
        return DB::table('products')->where('seller_id', $sellerId)
            ->where('hidden_reason', self::REASON)->whereNull('deleted_at');
    }

    public static function sellerIsSuspended(?int $sellerId): bool
    {
        if (!$sellerId) return false;
        $user = DB::table('users')->where('id', $sellerId)->first(['role', 'is_active']);
        return $user && $user->role === 'seller' && !$user->is_active;
    }

    private function flushCaches(): void
    {
        foreach ([fn () => SubcategoryBar::flush(), fn () => AdServer::flushEligible(),
                  fn () => app(\App\Services\Gender\GenderMenu::class)->flush(), fn () => RelatedProducts::bump()] as $flush) {
            try { $flush(); } catch (\Throwable $e) { Log::warning('[SuspendedSellerProducts] cache flush: ' . $e->getMessage()); }
        }
    }
}
