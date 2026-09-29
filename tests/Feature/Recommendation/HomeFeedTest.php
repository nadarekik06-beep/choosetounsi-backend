<?php

namespace Tests\Feature\Recommendation;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/home/feed
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Recommendation/HomeFeedTest.php
 */
class HomeFeedTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeTranslator();
        // No AI vectors in these tests: "similar" runs on content similarity only.
        Http::fake(['*/similar' => Http::response(['results' => [], 'seeds_used' => []])]);
        // Most tests use a few dozen products; exercise the strict (standard-catalog) rules
        // unless a test opts into small-catalog mode.
        $this->strictCatalogRules();
    }

    private function strictCatalogRules(): void
    {
        config(['recommendations.feed.small_catalog.max_products' => 0, 'recommendations.feed.small_catalog.min_sellers' => 0]);
    }

    private function smallCatalogRules(): void
    {
        config(['recommendations.feed.small_catalog.max_products' => 60, 'recommendations.feed.small_catalog.min_sellers' => 5]);
    }

    private function counts(array $feed): array
    {
        return array_count_values($this->allIds($feed));
    }

    /** $n products spread over $sellers sellers, all in $category. */
    private function catalog(Category $category, int $n, int $sellers = 4, array $attrs = []): array
    {
        $s = array_map(fn () => $this->makeUser('seller'), range(1, $sellers));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->makeProduct($s[$i % $sellers], $category, $attrs + ['price' => 30 + $i]);
        }
        return $out;
    }

    private function feed(array $headers = []): array
    {
        return $this->withHeaders($headers)->getJson('/api/home/feed')->assertOk()->json();
    }

    private function auth(User $u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('t')->plainTextToken];
    }

    private function allIds(array $feed): array
    {
        return collect($feed['sections'])->flatMap(fn ($s) => array_column($s['products'], 'id'))->all();
    }

    private function section(array $feed, string $key): ?array
    {
        return collect($feed['sections'])->firstWhere('key', $key);
    }

    private function event(User $u, string $type, Product $p, int $minutesAgo = 0): void
    {
        UserInteraction::create([
            'user_id' => $u->id, 'product_id' => $p->id, 'seller_id' => $p->seller_id,
            'category_id' => $p->category_id, 'event_type' => $type, 'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function sponsor(Product $p, int $priority = 30): void
    {
        DB::table('products')->where('id', $p->id)->update(['is_sponsored' => true, 'sponsored_priority' => $priority, 'sponsored_at' => now()]);
        DB::table('sponsorships')->insert([
            'seller_id' => $p->seller_id, 'product_id' => $p->id, 'status' => 'active', 'pricing_model' => 'legacy_daily',
            'start_at' => now()->subDay(), 'end_at' => now()->addWeek(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_guest_gets_cold_start_sections_without_duplicates(): void
    {
        foreach (range(1, 3) as $_) {
            $this->catalog($this->makeCategory(), 12);
        }

        $feed = $this->feed();

        $this->assertFalse($feed['personalized']);
        $types = array_column($feed['sections'], 'type');
        $this->assertContains('trending', $types);
        $this->assertContains('new_arrivals', $types);
        $this->assertContains('best_sellers', $types);
        $this->assertContains('top_rated', $types);
        $ids = $this->allIds($feed);
        $this->assertSame(count($ids), count(array_unique($ids)), 'a product appears in two sections');
        foreach ($feed['sections'] as $s) {
            $this->assertGreaterThanOrEqual(4, count($s['products']), "{$s['key']} is too small to show");
        }
    }

    public function test_core_rows_always_show_and_are_topped_up_when_their_signal_is_missing(): void
    {
        // 70 products, no reviews and no sales: best sellers / top rated have no own signal.
        foreach (range(1, 5) as $_) {
            $this->catalog($this->makeCategory(), 14, 7);
        }

        $feed = $this->feed();

        foreach (['trending', 'best_sellers', 'new_arrivals', 'top_rated'] as $key) {
            $row = $this->section($feed, $key);
            $this->assertNotNull($row, "$key missing");
            $this->assertGreaterThanOrEqual(10, count($row['products']), "$key too short");
        }
        $ids = $this->allIds($feed);
        $this->assertSame(count($ids), count(array_unique($ids)), 'standard catalog: every product at most once');
        $this->assertSame('standard', $feed['catalog_mode']);
    }

    public function test_best_sellers_rank_by_units_sold(): void
    {
        foreach (range(1, 5) as $_) {
            $products = $this->catalog($this->makeCategory(), 14, 7);
        }
        $buyer = $this->makeUser();
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $buyer->id, 'order_number' => 'RECO-' . Str::random(8), 'total_amount' => 10,
            'shipping_fee' => 0, 'discount_amount' => 0, 'status' => 'delivered', 'payment_status' => 'paid',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $products[3]->id, 'quantity' => 9, 'unit_price' => 10, 'price' => 10,
            'total' => 90, 'discount_amount' => 0, 'commission_percentage' => 0, 'commission_amount' => 0,
            'seller_amount' => 90, 'plan_used' => 'free', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->section($this->feed(), 'best_sellers');

        $this->assertSame($products[3]->id, $row['products'][0]['id']);
    }

    public function test_small_catalog_fills_every_core_row_reusing_products_at_most_twice(): void
    {
        $this->smallCatalogRules();
        $seller = $this->makeUser('seller');
        $cats = [$this->makeCategory(), $this->makeCategory(), $this->makeCategory()];
        $products = array_map(fn ($i) => $this->makeProduct($seller, $cats[$i % 3]), range(0, 13));   // 14 products, 1 seller

        $feed = $this->feed();

        $this->assertSame('small', $feed['catalog_mode']);
        foreach (['trending', 'best_sellers', 'new_arrivals', 'top_rated'] as $key) {
            $this->assertNotNull($this->section($feed, $key), "$key missing on a small catalog");
        }
        $counts = $this->counts($feed);
        $this->assertLessThanOrEqual(2, max($counts), 'no product in more than 2 rows');
        $this->assertCount(14, $counts, 'every product shown before any is repeated');
        foreach ($feed['sections'] as $s) {
            $ids = array_column($s['products'], 'id');
            $this->assertSame(count($ids), count(array_unique($ids)), "{$s['key']} repeats a product");
        }
    }

    public function test_small_catalog_warm_user_still_gets_all_core_rows(): void
    {
        $this->smallCatalogRules();
        $user = $this->makeUser();
        $seller = $this->makeUser('seller');
        $cat = $this->makeCategory();
        $products = array_map(fn () => $this->makeProduct($seller, $cat), range(1, 14));
        DB::table('seller_follows')->insert(['user_id' => $user->id, 'seller_id' => $seller->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->event($user, 'view', $products[0]);

        $feed = $this->feed($this->auth($user));

        foreach (['recommended', 'trending', 'best_sellers', 'new_arrivals'] as $key) {
            $this->assertNotNull($this->section($feed, $key), "$key missing");
        }
        $this->assertNotNull($this->section($feed, 'favorite_sellers'));
    }

    public function test_flash_deal_products_are_left_to_the_flash_deals_row(): void
    {
        foreach (range(1, 5) as $_) {
            $products = $this->catalog($this->makeCategory(), 14, 7);
        }
        $flash = $products[0];
        $promoId = DB::table('promotions')->insertGetId([
            'seller_id' => $flash->seller_id, 'name' => 'Flash', 'type' => 'flash_sale', 'discount_type' => 'percentage',
            'discount_value' => 20, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('promotion_products')->insert(['promotion_id' => $promoId, 'product_id' => $flash->id]);

        $this->assertNotContains($flash->id, $this->allIds($this->feed()));
    }

    public function test_unavailable_products_never_appear(): void
    {
        $cat = $this->makeCategory();
        $this->catalog($cat, 10);
        $seller = $this->makeUser('seller');
        $hidden = [
            $this->makeProduct($seller, $cat, ['stock' => 0]),
            $this->makeProduct($seller, $cat, ['is_active' => false]),
            $this->makeProduct($seller, $cat, ['is_approved' => false]),
        ];
        $deleted = $this->makeProduct($seller, $cat);
        $deleted->delete();
        $hidden[] = $deleted;

        $ids = $this->allIds($this->feed());

        foreach ($hidden as $p) {
            $this->assertNotContains($p->id, $ids);
        }
    }

    public function test_variant_stock_counts_as_in_stock(): void
    {
        $cat = $this->makeCategory();
        $this->catalog($cat, 10);
        $p = $this->makeProduct($this->makeUser('seller'), $cat, ['stock' => 0]);
        DB::table('product_variants')->insert(['product_id' => $p->id, 'stock' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertContains($p->id, $this->allIds($this->feed()));
    }

    public function test_warm_user_gets_recommendations_from_their_interests_and_no_duplicates(): void
    {
        $user   = $this->makeUser();
        $liked  = $this->makeCategory();
        $other  = $this->makeCategory();
        $likedProducts = $this->catalog($liked, 16);
        $this->catalog($other, 16);

        foreach (array_slice($likedProducts, 0, 3) as $i => $p) {
            $this->event($user, 'cart_add', $p, $i);
        }

        $feed = $this->feed($this->auth($user));

        $this->assertTrue($feed['personalized']);
        $rec = $this->section($feed, 'recommended');
        $this->assertNotNull($rec, 'recommended section missing');
        $inLiked = collect($rec['products'])->where('category_id', $liked->id)->count();
        $this->assertGreaterThanOrEqual(count($rec['products']) - 2, $inLiked, 'only exploration slots may leave the liked category');

        $ids = $this->allIds($feed);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_recommended_prefers_unseen_products_over_ones_just_viewed(): void
    {
        $user  = $this->makeUser();
        $liked = $this->makeCategory();
        $products = $this->catalog($liked, 20, 7);
        $viewed = $products[0];
        $this->event($user, 'view', $viewed);
        $this->event($user, 'cart_add', $products[1]);

        $rec = $this->section($this->feed($this->auth($user)), 'recommended');

        $this->assertNotNull($rec);
        $this->assertNotContains($viewed->id, array_slice(array_column($rec['products'], 'id'), 0, 3));
    }

    public function test_recommended_row_respects_seller_diversity_cap(): void
    {
        $user = $this->makeUser();
        $cat  = $this->makeCategory();
        $big  = $this->makeUser('seller');
        $bigProducts = array_map(fn ($i) => $this->makeProduct($big, $cat, ['price' => 40 + $i]), range(1, 12));
        $this->catalog($cat, 12, 6);
        $this->event($user, 'purchase', $bigProducts[0]);
        $this->event($user, 'favorite_add', $bigProducts[1]);

        $rec = $this->section($this->feed($this->auth($user)), 'recommended');

        $fromBig = collect($rec['products'])->where('seller_id', $big->id)->count();
        $this->assertLessThanOrEqual(config('recommendations.feed.max_per_seller'), $fromBig);
    }

    public function test_bought_products_are_excluded_from_all_rows(): void
    {
        $user = $this->makeUser();
        $cat  = $this->makeCategory();
        $products = $this->catalog($cat, 20);
        $bought = $products[0];
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $user->id, 'order_number' => 'RECO-' . Str::random(8), 'total_amount' => 10,
            'shipping_fee' => 0, 'discount_amount' => 0, 'status' => 'delivered', 'payment_status' => 'paid',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $bought->id, 'quantity' => 1, 'unit_price' => 10, 'price' => 10,
            'total' => 10, 'discount_amount' => 0, 'commission_percentage' => 0, 'commission_amount' => 0,
            'seller_amount' => 10, 'plan_used' => 'free', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->event($user, 'purchase', $bought);
        $this->event($user, 'view', $bought);

        $this->assertNotContains($bought->id, $this->allIds($this->feed($this->auth($user))));
    }

    public function test_favourites_recently_viewed_and_followed_seller_rows(): void
    {
        $user   = $this->makeUser();
        $cat    = $this->makeCategory();
        $this->catalog($cat, 20, 5);
        $favs   = $this->catalog($this->makeCategory(), 5, 2);
        $viewed = $this->catalog($this->makeCategory(), 5, 2);
        $shop   = $this->makeUser('seller');
        $shopProducts = array_map(fn () => $this->makeProduct($shop, $this->makeCategory()), range(1, 12));

        foreach ($favs as $p) {
            DB::table('favorites')->insert(['user_id' => $user->id, 'product_id' => $p->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($viewed as $i => $p) {
            $this->event($user, 'view', $p, $i);
        }
        DB::table('seller_follows')->insert(['user_id' => $user->id, 'seller_id' => $shop->id, 'created_at' => now(), 'updated_at' => now()]);

        $feed = $this->feed($this->auth($user));

        $favSection = $this->section($feed, 'favorites');
        $this->assertNotNull($favSection);
        $this->assertEqualsCanonicalizing(array_column($favs, 'id'), array_column($favSection['products'], 'id'));

        $recent = $this->section($feed, 'recently_viewed');
        $this->assertNotNull($recent);
        $this->assertSame($viewed[0]->id, $recent['products'][0]['id'], 'most recent view first');

        $sellers = $this->section($feed, 'favorite_sellers');
        $this->assertNotNull($sellers);
        $this->assertSame($shop->id, $sellers['meta']['sellers'][0]['id']);
        $this->assertTrue($sellers['meta']['sellers'][0]['followed']);
        $this->assertContains($sellers['products'][0]['id'], array_column($shopProducts, 'id'));
    }

    public function test_seller_bought_from_in_two_orders_becomes_a_favourite_seller(): void
    {
        $user = $this->makeUser();
        $this->catalog($this->makeCategory(), 20);
        $shop = $this->makeUser('seller');
        $items = array_map(fn () => $this->makeProduct($shop, $this->makeCategory()), range(1, 14));

        foreach ([$items[0], $items[1]] as $p) {
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $user->id, 'order_number' => 'RECO-' . Str::random(8), 'total_amount' => 10,
                'shipping_fee' => 0, 'discount_amount' => 0, 'status' => 'delivered', 'payment_status' => 'paid',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'product_id' => $p->id, 'quantity' => 1, 'unit_price' => 10, 'price' => 10,
                'total' => 10, 'discount_amount' => 0, 'commission_percentage' => 0, 'commission_amount' => 0,
                'seller_amount' => 10, 'plan_used' => 'free', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($user, 'purchase', $p);
        }

        $row = $this->section($this->feed($this->auth($user)), 'favorite_sellers');

        $this->assertNotNull($row);
        $this->assertSame($shop->id, $row['meta']['sellers'][0]['id']);
        $this->assertFalse($row['meta']['sellers'][0]['followed']);
        $ids = array_column($row['products'], 'id');
        $this->assertNotContains($items[0]->id, $ids, 'already bought');
        $this->assertNotContains($items[1]->id, $ids, 'already bought');
    }

    public function test_sponsored_favourites_stay_in_the_favourites_row(): void
    {
        $user = $this->makeUser();
        $products = $this->catalog($this->makeCategory(), 30, 6);
        foreach (array_slice($products, 0, 6) as $p) {
            $this->sponsor($p);
        }
        foreach ([$products[0], $products[1], $products[20]] as $p) {   // 2 of 3 favourites are sponsored
            DB::table('favorites')->insert(['user_id' => $user->id, 'product_id' => $p->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->event($user, 'view', $products[25]);

        $feed = $this->feed($this->auth($user));

        $fav = $this->section($feed, 'favorites');
        $this->assertNotNull($fav);
        $this->assertEqualsCanonicalizing([$products[0]->id, $products[1]->id, $products[20]->id], array_column($fav['products'], 'id'));
        $this->assertNotNull($this->section($feed, 'sponsored'), 'the other 4 ads still fill the sponsored row');
    }

    public function test_small_sections_are_hidden(): void
    {
        $user = $this->makeUser();
        $this->catalog($this->makeCategory(), 20);
        $fav = $this->catalog($this->makeCategory(), 1, 1);   // own lists show from 2 items
        foreach ($fav as $p) {
            DB::table('favorites')->insert(['user_id' => $user->id, 'product_id' => $p->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->event($user, 'view', $fav[0]);

        $feed = $this->feed($this->auth($user));

        $this->assertNull($this->section($feed, 'favorites'));
    }

    public function test_sponsored_row_is_labelled_and_deduplicated(): void
    {
        $cat = $this->makeCategory();
        $products = $this->catalog($cat, 24);
        foreach (array_slice($products, 0, 5) as $p) {
            $this->sponsor($p);
        }

        $feed = $this->feed();

        $row = $this->section($feed, 'sponsored');
        $this->assertNotNull($row);
        foreach ($row['products'] as $card) {
            $this->assertTrue($card['is_sponsored']);
            $this->assertNotEmpty($card['sponsor_data']['id'], 'needed for impression/click tracking');
        }
        foreach ($feed['sections'] as $s) {
            if ($s['key'] !== 'sponsored') {
                foreach ($s['products'] as $card) {
                    $this->assertFalse($card['is_sponsored'], 'organic placements must not be labelled sponsored');
                }
            }
        }
        $ids = $this->allIds($feed);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_too_few_sponsored_products_are_injected_labelled_into_first_row(): void
    {
        $products = $this->catalog($this->makeCategory(), 24);
        $this->sponsor($products[20]);
        $this->sponsor($products[21]);

        $feed = $this->feed();

        $this->assertNull($this->section($feed, 'sponsored'));
        $paid = collect($feed['sections'])->flatMap(fn ($s) => $s['products'])->where('is_sponsored', true)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$products[20]->id, $products[21]->id], $paid);
    }

    public function test_feed_is_stable_within_the_rotation_window(): void
    {
        $this->catalog($this->makeCategory(), 30);
        $sid = ['X-Session-Id' => $this->guestSession()];

        $first = $this->allIds($this->feed($sid));
        Cache::flush();   // force a rebuild — same 5-minute window must give the same order
        $this->assertSame($first, $this->allIds($this->feed($sid)));
    }

    public function test_guest_session_activity_personalizes_the_feed(): void
    {
        $liked = $this->makeCategory();
        $likedProducts = $this->catalog($liked, 16);
        $this->catalog($this->makeCategory(), 16);
        $sid = $this->guestSession();

        foreach (array_slice($likedProducts, 0, 3) as $p) {
            $this->withHeaders(['X-Session-Id' => $sid])
                ->postJson('/api/track', ['events' => [['type' => 'click', 'product_id' => $p->id, 'source_section' => 'trending']]]);
        }
        $this->travel(2)->seconds();

        $feed = $this->feed(['X-Session-Id' => $sid]);

        $this->assertTrue($feed['personalized']);
        $this->assertNotNull($this->section($feed, 'recommended'));
    }
}
