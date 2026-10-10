<?php

namespace Tests\Feature\Shop;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\Review;
use App\Models\SellerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * /shop landing data: GET /api/shop/overview (live counts, category rail,
 * pepper sellers, deals) and the catalogue filters it relies on
 * (seller_plan, on_sale, sort=rating) on GET /api/products.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Shop/ShopOverviewTest.php
 */
class ShopOverviewTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTranslator();
        Cache::flush();
    }

    private function shop(string $plan, string $name = null): User
    {
        $seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $seller->id, 'full_name' => 'Shop Owner', 'phone_number' => '20000000',
            'business_name' => $name ?? 'Shop ' . Str::random(6), 'business_category' => 'other',
            'wilaya' => 'Sfax', 'city' => 'Sfax', 'status' => 'approved', 'plan' => $plan,
        ]);
        return $seller;
    }

    private function review(Product $product, int $rating): void
    {
        // A review belongs to a delivered order line
        $client  = $this->makeUser();
        $orderId = DB::table('orders')->insertGetId(['user_id' => $client->id, 'order_number' => 'T-' . Str::random(10),
            'subtotal' => 50, 'total_amount' => 50, 'status' => 'delivered']);
        $so = DB::table('seller_orders')->insertGetId(['order_id' => $orderId, 'seller_id' => $product->seller_id, 'status' => 'delivered',
            'subtotal' => 50, 'seller_net_amount' => 50]);
        $itemId = DB::table('order_items')->insertGetId(['order_id' => $orderId, 'seller_order_id' => $so, 'product_id' => $product->id,
            'quantity' => 1, 'unit_price' => 50, 'price' => 50, 'total' => 50, 'net_total' => 50, 'seller_amount' => 50]);
        DB::table('reviews')->insert(['user_id' => $client->id, 'product_id' => $product->id, 'order_item_id' => $itemId,
            'seller_id' => $product->seller_id, 'rating' => $rating, 'body' => 'Test review', 'status' => 'approved']);
    }

    private function promotion(User $seller, Product $product, array $attrs = []): Promotion
    {
        $promo = Promotion::create(array_merge([
            'seller_id' => $seller->id, 'name' => 'Test promo ' . Str::random(4), 'type' => 'discount',
            'discount_type' => 'percentage', 'discount_value' => 20, 'status' => 'active', 'priority' => 5,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3),
        ], $attrs));
        $promo->products()->attach($product->id);
        return $promo;
    }

    public function test_stats_are_live_database_counts(): void
    {
        $category = $this->makeCategory();
        $this->makeProduct($this->shop('free'), $category);
        $this->makeProduct($this->shop('red'), $category, ['is_active' => false]);

        $stats = $this->getJson('/api/shop/overview')->assertOk()->json('data.stats');

        $this->assertSame(Product::available()->count(), $stats['products']);
        $this->assertSame(Review::approved()->count(), $stats['reviews']);
        $this->assertGreaterThanOrEqual(1, $stats['sellers']);
        $this->assertGreaterThanOrEqual(1, $stats['categories']);
        // The admin's client delivery fee (Delivery & Fees), read live
        $this->assertEquals(\App\Support\Millimes::toFloat(app(\App\Services\Delivery\DeliverySettings::class)->clientFee()), $stats['delivery_fee']);
        $this->assertSame(Product::available()->where('delivery_fee', 0)->count(), $stats['free_delivery_products']);
    }

    public function test_categories_without_products_are_left_out(): void
    {
        $full  = $this->makeCategory();
        $empty = $this->makeCategory();
        $this->makeProduct($this->shop('free'), $full);
        $this->makeProduct($this->shop('free'), $full);

        $rail = collect($this->getJson('/api/shop/overview')->json('data.categories'))->keyBy('slug');

        $this->assertSame(2, $rail[$full->slug]['products_count']);
        $this->assertArrayNotHasKey($empty->slug, $rail->all());
    }

    public function test_sellers_carry_tier_counts_and_rating_black_first(): void
    {
        $category = $this->makeCategory();
        $black = $this->shop('black', 'Black Test Shop');
        $green = $this->shop('free', 'Green Test Shop');
        $idle  = $this->shop('red', 'Idle Test Shop');      // nothing on sale → not shown
        $p = $this->makeProduct($black, $category);
        $this->makeProduct($black, $category);
        $this->makeProduct($green, $category);
        $this->review($p, 4);
        $this->review($p, 5);
        DB::table('seller_follows')->insert(['user_id' => $this->makeUser()->id, 'seller_id' => $black->id, 'created_at' => now(), 'updated_at' => now()]);

        $sellers = collect($this->getJson('/api/shop/overview')->json('data.sellers'));
        $byId = $sellers->keyBy('id');

        $this->assertSame('black', $byId[$black->id]['plan']);
        $this->assertSame('Black Test Shop', $byId[$black->id]['business_name']);
        $this->assertSame(2, $byId[$black->id]['products_count']);
        $this->assertSame(1, $byId[$black->id]['followers']);
        $this->assertEquals(4.5, $byId[$black->id]['rating']);
        $this->assertSame('free', $byId[$green->id]['plan']);
        $this->assertArrayNotHasKey($idle->id, $byId->all());
        $this->assertSame('black', $sellers->first()['plan']);
    }

    public function test_deals_put_flash_sales_first_with_their_stock(): void
    {
        $seller = $this->shop('red', 'Deals Shop');
        $category = $this->makeCategory();
        $discounted = $this->makeProduct($seller, $category);
        $flashed    = $this->makeProduct($seller, $category);
        $this->promotion($seller, $discounted, ['ends_at' => now()->addHour()]);
        $this->promotion($seller, $flashed, ['type' => 'flash_sale', 'flash_stock' => 10, 'ends_at' => now()->addDays(2)]);

        $deals = collect($this->getJson('/api/shop/overview')->json('data.deals'));
        $ids = $deals->pluck('id')->all();

        $this->assertLessThan(array_search($discounted->id, $ids), array_search($flashed->id, $ids));
        $flash = $deals->firstWhere('id', $flashed->id);
        $this->assertTrue($flash['deal']['is_flash_sale']);
        $this->assertSame(10, $flash['deal']['flash_stock']);
        $this->assertSame('Deals Shop', $flash['seller']['business_name']);
        $this->assertLessThan(50, $flash['final_price']);
        $this->assertSame([], $flash['variants']);
        $this->assertNull($flash['avg_rating']);
    }

    public function test_catalog_filters_on_seller_tier_and_sale(): void
    {
        $category = $this->makeCategory();
        $black = $this->shop('black');
        $green = $this->shop('free');
        $onSale = $this->makeProduct($black, $category);
        $plain  = $this->makeProduct($green, $category);
        $this->promotion($black, $onSale);

        $tier = collect($this->getJson("/api/products?category_id={$category->id}&seller_plan=black")->json('data.data'));
        $this->assertSame([$onSale->id], $tier->pluck('id')->all());
        $this->assertSame('black', $tier->first()['seller']['plan']);
        $this->assertNotNull($tier->first()['seller']['business_name']);

        $sale = collect($this->getJson("/api/products?category_id={$category->id}&on_sale=1")->json('data.data'));
        $this->assertSame([$onSale->id], $sale->pluck('id')->all());

        $green = collect($this->getJson("/api/products?category_id={$category->id}&seller_plan=free")->json('data.data'));
        $this->assertSame([$plain->id], $green->pluck('id')->all());
    }

    public function test_rating_sort_puts_best_rated_first_and_unrated_last(): void
    {
        $category = $this->makeCategory();
        $seller = $this->shop('free');
        $unrated = $this->makeProduct($seller, $category);
        $good    = $this->makeProduct($seller, $category);
        $best    = $this->makeProduct($seller, $category);
        $this->review($good, 3);
        $this->review($best, 5);

        $rows = collect($this->getJson("/api/products?category_id={$category->id}&sort=rating")->json('data.data'));

        $this->assertSame([$best->id, $good->id, $unrated->id], $rows->pluck('id')->all());
        $this->assertEquals(5, $rows->first()['avg_rating']);
        $this->assertSame(1, $rows->first()['reviews_count']);
        $this->assertNull($rows->last()['avg_rating']);
    }
}
