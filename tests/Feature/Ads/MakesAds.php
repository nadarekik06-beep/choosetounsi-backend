<?php

namespace Tests\Feature\Ads;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Ads\AdSettings;
use App\Services\Ads\AdWalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Recommendation\MakesCatalog;

/** Throwaway sellers, ready-to-boost products and funded wallets for the ad tests. */
trait MakesAds
{
    use MakesCatalog;

    protected function setUpAds(): void
    {
        Cache::flush();
        SubscriptionPlan::flushCache();
        app(AdSettings::class)->flush();
        $this->fakeTranslator();
        // Similarity falls back to content matching; never call the real AI service.
        Http::fake(['*' => Http::response(['results' => [], 'seeds_used' => []])]);
    }

    /** An approved seller on $plan (free | red | black | any plan slug). */
    protected function seller(string $plan = 'red'): User
    {
        $seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $seller->id, 'full_name' => 'Ads Seller', 'phone_number' => '20000000',
            'business_name' => 'Ads Shop ' . Str::random(5), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => $plan,
        ]);
        return $seller;
    }

    /** A product that passes the readiness check: listed, in stock, 3 photos, a real description. */
    protected function readyProduct(User $seller, ?Category $category = null, array $attrs = [], int $images = 3): Product
    {
        $product = $this->makeProduct($seller, $category ?? $this->makeCategory(), array_merge([
            'description' => str_repeat('Handmade in Tunisia with care, natural materials. ', 4),
            'price'       => 50,
            'stock'       => 10,
        ], $attrs));
        for ($i = 1; $i <= $images; $i++) {
            DB::table('product_images')->insert([
                'product_id' => $product->id, 'image_path' => "products/test-{$product->id}-{$i}.jpg",
                'order' => $i, 'is_primary' => $i === 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $product->fresh();
    }

    protected function fund(User $seller, float $amount): void
    {
        app(AdWalletService::class)->topUp($seller->id, $amount, 'test');
    }

    protected function campaignData(Product $product, array $overrides = []): array
    {
        return array_merge(['product_id' => $product->id, 'daily_budget' => 5, 'max_cpc' => 0.3], $overrides);
    }

    protected function planWithLimit(int $maxSponsored, int $tier = 1): string
    {
        $slug = 'ads-' . Str::lower(Str::random(6));
        DB::table('subscription_plans')->insert([
            'slug' => $slug, 'name' => 'Ads Test Plan', 'tier' => $tier, 'max_sponsored_products' => $maxSponsored,
            'features' => json_encode(['sponsorships' => true]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        SubscriptionPlan::flushCache();
        return $slug;
    }
}
