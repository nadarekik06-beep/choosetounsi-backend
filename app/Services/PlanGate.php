<?php

namespace App\Services;

use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Backend enforcement of plan features and limits (subscription_plans).
 *
 * Every check returns null when allowed, or a ready-to-return 403 JsonResponse
 * with a machine-readable `code` the seller dashboard can react to.
 */
class PlanGate
{
    /** @return array{0: ?SubscriptionPlan, 1: ?SellerSubscription, 2: ?SellerApplication} */
    public function context(int $sellerId): array
    {
        $app = SellerApplication::where('user_id', $sellerId)->where('status', 'approved')->first();
        if (!$app) return [null, null, null];
        $sub = SellerSubscription::where('seller_application_id', $app->id)->first();
        return [SubscriptionPlan::forSlug($sub?->current_plan ?? $app->plan), $sub, $app];
    }

    public function planFor(int $sellerId): SubscriptionPlan
    {
        return $this->context($sellerId)[0] ?? SubscriptionPlan::defaultPlan();
    }

    public function feature(int $sellerId, string $feature): ?JsonResponse
    {
        [$plan, $sub, $app] = $this->context($sellerId);

        if (!$app) {
            return $this->deny('Approved seller account required.', 'NOT_SELLER');
        }
        if ($sub && $sub->isSuspended()) {
            return $this->deny('Your subscription has been suspended. Please contact support.', 'SUBSCRIPTION_SUSPENDED');
        }
        if (!$plan->hasFeature($feature)) {
            $label  = SubscriptionPlan::FEATURES[$feature] ?? $feature;
            $offers = SubscriptionPlan::offered()->ordered()->get()->filter(fn($p) => $p->hasFeature($feature));
            $names  = $offers->pluck('name')->join(', ', ' or ');
            return $this->deny(
                "{$label} is not included in your {$plan->name} plan." . ($names ? " Upgrade to {$names}." : ''),
                'PLAN_REQUIRED',
                ['feature' => $feature, 'current_plan' => $plan->slug, 'required_plan' => optional($offers->first())->slug]
            );
        }
        return null;
    }

    /** Can the seller create one more product? */
    public function canAddProduct(int $sellerId): ?JsonResponse
    {
        $plan = $this->planFor($sellerId);
        if ($plan->max_products === null) return null;

        $count = DB::table('products')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->whereNull('hidden_reason')->orWhere('hidden_reason', '!=', 'over_plan_limit'))
            ->count();

        if ($count >= $plan->max_products) {
            return $this->deny(
                "Your {$plan->name} plan allows {$plan->max_products} products and you have {$count}. Upgrade your plan or delete a product to add more.",
                'PRODUCT_LIMIT_REACHED',
                ['limit' => $plan->max_products, 'current' => $count]
            );
        }
        return null;
    }

    /** Would the product end up with more images than the plan allows? */
    public function imagesWithinLimit(int $sellerId, int $totalAfterSave): ?JsonResponse
    {
        $plan = $this->planFor($sellerId);
        if ($plan->max_images_per_product === null || $totalAfterSave <= $plan->max_images_per_product) return null;

        return $this->deny(
            "Your {$plan->name} plan allows {$plan->max_images_per_product} images per product (this product would have {$totalAfterSave}).",
            'IMAGE_LIMIT_REACHED',
            ['limit' => $plan->max_images_per_product, 'requested' => $totalAfterSave]
        );
    }

    /** Can the seller run one more active sponsorship? */
    public function canSponsor(int $sellerId): ?JsonResponse
    {
        if ($deny = $this->feature($sellerId, 'sponsorships')) return $deny;

        $plan = $this->planFor($sellerId);
        if ($plan->max_sponsored_products === null) return null;

        // Both sponsorship mechanisms count: paid/quota sponsorships and the
        // Black Pepper hub's direct "sponsored" flag on products.
        $active = DB::table('sponsorships')->where('seller_id', $sellerId)->where('status', 'active')->pluck('product_id')
            ->merge(DB::table('products')->where('seller_id', $sellerId)->where('is_sponsored', true)->whereNull('deleted_at')->pluck('id'))
            ->unique()->count();
        if ($active >= $plan->max_sponsored_products) {
            return $this->deny(
                "Your {$plan->name} plan allows {$plan->max_sponsored_products} sponsored product(s) at a time.",
                'SPONSOR_LIMIT_REACHED',
                ['limit' => $plan->max_sponsored_products, 'current' => $active]
            );
        }
        return null;
    }

    private function deny(string $message, string $code, array $extra = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code] + $extra, 403);
    }
}
