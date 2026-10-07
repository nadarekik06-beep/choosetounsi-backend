<?php

namespace Tests\Feature\VisitorInsights;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\VisitorInsights\FunnelAggregator;
use App\Services\VisitorInsights\FunnelBenchmarks;
use App\Services\VisitorInsights\FunnelDiagnosis;
use App\Services\VisitorInsights\FunnelExclusions;
use App\Services\VisitorInsights\VisitorInsights;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Analyse des visiteurs: tracking, exclusions, daily roll-ups, benchmarks with
 * fallback, diagnosis rules, API + tier gating, applied actions.
 * Disposable sellers / products / buyers only (choosetounsi_test).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/VisitorInsights/VisitorInsightsTest.php
 */
class VisitorInsightsTest extends TestCase
{
    use DatabaseTransactions;

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1';

    private User $seller;
    private Category $category;
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->today = CarbonImmutable::now(config('funnel.timezone'))->startOfDay();
        $this->seller = $this->makeSeller('black');
        $s = Str::random(6);
        $this->category = Category::create(['name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s", 'slug' => "vi-cat-$s", 'is_active' => true]);
        // Benchmarks are platform-wide: start from a clean slate inside the transaction
        DB::table('funnel_benchmarks')->delete();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' ' . Str::random(5), 'email' => $role . '_' . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true, 'locale' => 'fr',
        ]);
    }

    private function makeSeller(string $plan): User
    {
        $u = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $u->id, 'full_name' => 'Funnel Test', 'phone_number' => '20000000',
            'business_name' => 'Funnel Shop ' . Str::random(4), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => $plan,
        ]);
        return $u;
    }

    private function product(array $attrs = [], ?User $seller = null, int $images = 3): Product
    {
        $name = $attrs['name'] ?? 'Funnel Product ' . Str::random(6) . ' — modèle complet';
        unset($attrs['name']);
        $p = Product::create($attrs + [
            'seller_id' => ($seller ?? $this->seller)->id, 'category_id' => $this->category->id, 'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(4), 'price' => 50, 'stock' => 20, 'is_approved' => true, 'is_active' => true,
            'description' => str_repeat('Une description complète et précise. ', 10), 'short_description' => 'Résumé court',
        ]);
        DB::table('products')->where('id', $p->id)->update(['created_at' => $this->today->subDays(60)->utc()]);
        foreach (range(1, $images) as $i) {
            DB::table('product_images')->insert(['product_id' => $p->id, 'image_path' => "test/$p->id-$i.jpg", 'order' => $i,
                'is_primary' => $i === 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $p->fresh();
    }

    /** Raw interaction rows on a day ($ago days back), one new session each unless $session is given. */
    private function events(Product $p, string $type, int $n, int $ago = 1, ?string $source = 'search', ?string $session = null, ?int $userId = null): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['user_id' => $userId, 'session_id' => $session ?? (string) Str::uuid(), 'product_id' => $p->id, 'seller_id' => $p->seller_id,
                'category_id' => $p->category_id, 'event_type' => $type, 'traffic_source' => $source, 'device' => 'mobile',
                'created_at' => $this->today->subDays($ago)->setTime(12, $i % 60)->utc()];
        }
        foreach (array_chunk($rows, 500) as $chunk) DB::table('user_interactions')->insert($chunk);
    }

    private function sale(Product $p, int $ago, ?User $buyer = null): void
    {
        $buyer ??= $this->makeUser('client');
        $at = $this->today->subDays($ago)->setTime(15, 0)->utc();
        $orderId = DB::table('orders')->insertGetId(['user_id' => $buyer->id, 'order_number' => 'VI-' . Str::random(10),
            'total_amount' => $p->price, 'status' => 'delivered', 'created_at' => $at, 'updated_at' => $at]);
        $so = DB::table('seller_orders')->insertGetId(['order_id' => $orderId, 'seller_id' => $p->seller_id, 'status' => 'delivered',
            'subtotal' => $p->price, 'created_at' => $at, 'updated_at' => $at]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'seller_order_id' => $so, 'product_id' => $p->id, 'quantity' => 1,
            'unit_price' => $p->price, 'price' => $p->price, 'total' => $p->price, 'net_total' => $p->price, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function aggregate(int $days = 3): void
    {
        $agg = app(FunnelAggregator::class);
        for ($d = $days; $d >= 1; $d--) $agg->aggregateDay($this->today->subDays($d));
    }

    private function track(array $events, ?string $sid = null, string $ua = self::UA)
    {
        return $this->withHeaders(['X-Session-Id' => $sid ?? (string) Str::uuid(), 'User-Agent' => $ua])
            ->postJson('/api/track', ['events' => $events]);
    }

    // ── Tracking ────────────────────────────────────────────────────────────

    public function test_impressions_count_once_per_session_and_listing_context(): void
    {
        $p = $this->product();
        $sid = (string) Str::uuid();
        $this->track([['type' => 'impression', 'product_id' => $p->id, 'source_section' => 'search'],
                      ['type' => 'impression', 'product_id' => $p->id, 'source_section' => 'search'],
                      ['type' => 'impression', 'product_id' => $p->id, 'source_section' => 'home_flash']], $sid)->assertStatus(202);
        $this->track([['type' => 'impression', 'product_id' => $p->id, 'source_section' => 'search']], $sid);

        $rows = DB::table('product_funnel_events')->where('product_id', $p->id)->where('event', 'impression')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['search', 'home'], $rows->pluck('traffic_source')->all());
        $this->assertSame('mobile', $rows->first()->device);
    }

    public function test_checkout_start_is_deduplicated_per_session_and_order_attempt(): void
    {
        $p = $this->product();
        $sid = (string) Str::uuid();
        $ref = (string) Str::uuid();
        $this->track([['type' => 'checkout_start', 'product_id' => $p->id, 'ref' => $ref]], $sid);
        $this->track([['type' => 'checkout_start', 'product_id' => $p->id, 'ref' => $ref]], $sid);   // page refresh
        $this->track([['type' => 'checkout_start', 'product_id' => $p->id]], $sid);                  // no attempt id: ignored
        $this->track([['type' => 'checkout_start', 'product_id' => $p->id, 'ref' => (string) Str::uuid()]], $sid); // next order

        $this->assertSame(2, DB::table('product_funnel_events')->where('product_id', $p->id)->where('event', 'checkout_start')->count());
    }

    public function test_bots_are_dropped_and_views_carry_source_and_device(): void
    {
        $p = $this->product();
        $this->track([['type' => 'view', 'product_id' => $p->id, 'source_section' => 'search_photo']], null, 'Googlebot/2.1 (+http://www.google.com/bot.html)')
            ->assertStatus(202)->assertJsonPath('recorded', 0);
        $this->assertSame(0, DB::table('user_interactions')->where('product_id', $p->id)->count());

        $this->track([['type' => 'view', 'product_id' => $p->id, 'source_section' => 'external']]);
        $row = DB::table('user_interactions')->where('product_id', $p->id)->first();
        $this->assertSame('external', $row->traffic_source);
        $this->assertSame('mobile', $row->device);
        $this->assertSame(0, (int) $row->funnel_excluded);
    }

    public function test_the_sellers_own_views_are_flagged_even_as_a_guest_on_the_same_browser(): void
    {
        $p = $this->product();
        $sid = (string) Str::uuid();
        $token = $this->seller->createToken('t')->plainTextToken;
        $this->withHeaders(['X-Session-Id' => $sid, 'User-Agent' => self::UA, 'Authorization' => "Bearer $token"])
            ->postJson('/api/track', ['events' => [['type' => 'view', 'product_id' => $p->id]]]);
        $this->assertSame(1, (int) DB::table('user_interactions')->where('product_id', $p->id)->value('funnel_excluded'));

        // Later, logged out on the same browser
        $this->app['auth']->forgetGuards();
        $other = $this->product();
        $this->track([['type' => 'view', 'product_id' => $other->id]], $sid);
        $this->assertSame(1, (int) DB::table('user_interactions')->where('product_id', $other->id)->value('funnel_excluded'));
    }

    // ── History clean-up + roll-up ──────────────────────────────────────────

    public function test_backfill_excludes_seller_staff_and_bot_history_and_rolls_up_unique_views(): void
    {
        $p = $this->product();
        $admin = $this->makeUser('admin');
        $session = (string) Str::uuid();
        $this->events($p, 'view', 1, 2, null, $session, $this->seller->id);       // seller, logged in
        $this->events($p, 'view', 2, 2, null, $session);                          // seller's browser, logged out
        $this->events($p, 'view', 1, 2, null, null, $admin->id);                   // staff
        $this->events($p, 'view', config('funnel.bot_views_per_day') + 1, 2, null, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'); // bot volume
        $buyerSession = (string) Str::uuid();
        $this->events($p, 'view', 3, 2, null, $buyerSession);                      // one buyer, 3 views same day
        $this->events($p, 'view', 4, 2, null);                                      // 4 other buyers
        DB::table('user_interactions')->where('product_id', $p->id)->update(['traffic_source' => null]);

        app(FunnelExclusions::class)->markHistory();
        app(FunnelAggregator::class)->backfillSources();
        $this->aggregate();

        $s = DB::table('product_daily_stats')->where('product_id', $p->id)->first();
        $this->assertSame(5, (int) $s->views, 'one view per session per day, excluded actors not counted');
        $this->assertSame(5, (int) $s->views_direct, 'no click before → direct');
        $this->assertSame(0, (int) DB::table('product_daily_traffic')->where('product_id', $p->id)->where('traffic_source', '!=', 'direct')->sum('views'));
    }

    public function test_orders_come_from_seller_orders_and_are_attributed_to_the_buyers_last_view(): void
    {
        $p = $this->product();
        $buyer = $this->makeUser('client');
        $this->events($p, 'view', 1, 2, 'category', null, $buyer->id);
        $this->sale($p, 2, $buyer);
        $this->aggregate();

        $s = DB::table('product_daily_stats')->where('product_id', $p->id)->first();
        $this->assertSame(1, (int) $s->orders);
        $this->assertEquals(50, (float) $s->revenue);
        $this->assertSame(1, (int) DB::table('product_daily_traffic')->where('product_id', $p->id)->where('traffic_source', 'category')->value('orders'));
    }

    // ── Diagnosis rules ─────────────────────────────────────────────────────

    private function bench(array $values, string $scope = 'category'): \Closure
    {
        return fn (string $m) => array_key_exists($m, $values) ? ['value' => $values[$m], 'scope' => $scope] : null;
    }

    private function facts(array $o = []): array
    {
        return $o + ['price' => 100, 'delivery_fee' => 7, 'quality_score' => 85, 'quality_weakest' => null, 'reviews' => 3,
                     'stock' => 10, 'variants' => 0, 'variants_out' => 0, 'listed_days' => 60, 'period_days' => 30];
    }

    public function test_too_few_views_means_not_enough_data_never_ok(): void
    {
        $d = app(FunnelDiagnosis::class)->diagnose(['impressions' => null, 'clicks' => 2, 'views' => 12, 'carts' => 0, 'checkouts' => null, 'orders' => 0],
            $this->facts(), $this->bench(['view_to_cart' => 0.1, 'conversion' => 0.03]));
        $this->assertSame('insufficient', $d['status']);
    }

    public function test_product_page_causes_are_checked_in_order_and_lost_revenue_is_never_negative(): void
    {
        $dx = app(FunnelDiagnosis::class);
        $m = ['impressions' => null, 'clicks' => 50, 'views' => 200, 'carts' => 2, 'checkouts' => null, 'orders' => 1];
        $bench = $this->bench(['view_to_cart' => 0.1, 'conversion' => 0.03, 'median_price' => 60]);

        $d = $dx->diagnose($m, $this->facts(), $bench);
        $this->assertSame(['problem', 'product_page', 'price_high'], [$d['status'], $d['stage'], $d['code']]);
        $this->assertEquals(round(200 * (0.03 - 0.005) * 100, 1), $d['lost_revenue']);
        $this->assertSame('discount', $d['actions'][0]['kind']);
        $this->assertSame(30, $d['actions'][0]['pct']);   // 100 → 60 is 40 %, capped at 30

        $d = $dx->diagnose($m, $this->facts(['price' => 55, 'quality_score' => 40, 'quality_weakest' => 'photos']), $bench);
        $this->assertSame('listing_quality', $d['code']);
        $this->assertSame('listing_quality', $d['actions'][0]['kind']);

        $d = $dx->diagnose($m, $this->facts(['price' => 55, 'reviews' => 0]), $bench);
        $this->assertSame('no_reviews', $d['code']);

        // Beats the benchmark conversion while still adding little to cart: loss clamps to 0
        $d = $dx->diagnose(['impressions' => null, 'clicks' => 50, 'views' => 200, 'carts' => 8, 'checkouts' => null, 'orders' => 8],
            $this->facts(['price' => 55]), $this->bench(['view_to_cart' => 0.1, 'conversion' => 0.03]));
        $this->assertSame('low_add_to_cart', $d['code']);
        $this->assertEquals(0, $d['lost_revenue']);
    }

    public function test_cart_stage_points_at_out_of_stock_variants_and_click_stage_at_visibility(): void
    {
        $dx = app(FunnelDiagnosis::class);
        $d = $dx->diagnose(['impressions' => null, 'clicks' => 40, 'views' => 200, 'carts' => 25, 'checkouts' => null, 'orders' => 2],
            $this->facts(['variants' => 4, 'variants_out' => 2]), $this->bench(['view_to_cart' => 0.1, 'cart_to_order' => 0.45, 'conversion' => 0.04]));
        $this->assertSame(['cart', 'stock_variants'], [$d['stage'], $d['code']]);

        $d = $dx->diagnose(['impressions' => null, 'clicks' => 1, 'views' => 3, 'carts' => 0, 'checkouts' => null, 'orders' => 0],
            $this->facts(), $this->bench(['views_per_product' => 150, 'conversion' => 0.03]));
        $this->assertSame(['click', 'low_visibility'], [$d['stage'], $d['code']]);
        $this->assertSame('category', $d['evidence'][0]['scope']);
    }

    // ── Benchmarks: privacy floor + fallback chain ──────────────────────────

    public function test_benchmark_needs_five_shops_then_falls_back_to_the_sellers_previous_period(): void
    {
        $bench = app(FunnelBenchmarks::class);
        $sellers = [$this->seller];
        foreach (range(1, 3) as $i) $sellers[] = $this->makeSeller('free');
        foreach ($sellers as $s) {
            $p = $this->product([], $s);
            $this->events($p, 'view', 30, 2);
            $this->events($p, 'cart_add', 3, 2);
        }
        $this->aggregate();
        $bench->build($this->today->subDay());
        $this->assertNull(DB::table('funnel_benchmarks')->where('scope', 'category')->where('scope_id', $this->category->id)->first(), '4 shops: below the floor');
        $this->assertSame(['value' => 0.05, 'scope' => 'own_previous'],
            (new FunnelBenchmarks())->resolve('view_to_cart', $this->category->id, null, 7, 0.05));

        $fifth = $this->makeSeller('free');
        $p = $this->product([], $fifth);
        $this->events($p, 'view', 30, 2);
        $this->events($p, 'cart_add', 3, 2);
        $this->aggregate();
        $bench->build($this->today->subDay());
        $r = (new FunnelBenchmarks())->resolve('view_to_cart', $this->category->id, 999999, 7, 0.05);
        $this->assertSame('category', $r['scope'], 'unknown subcategory → category');
        $this->assertEqualsWithDelta(0.1, $r['value'], 0.0001);
    }

    // ── API ─────────────────────────────────────────────────────────────────

    public function test_black_pepper_only_and_an_empty_store_is_not_reported_as_fine(): void
    {
        foreach (['red', 'free'] as $plan) {
            Sanctum::actingAs($this->makeSeller($plan));
            $this->getJson('/api/seller/black/visitor-insights')->assertForbidden()->assertJsonPath('code', 'PLAN_REQUIRED');
            $this->postJson('/api/seller/black/visitor-insights/actions', ['product_id' => 1, 'kind' => 'edit'])->assertForbidden();
        }
        Sanctum::actingAs($this->seller);
        $res = $this->getJson('/api/seller/black/visitor-insights?period=7')->assertOk();
        $res->assertJsonPath('data.state', 'no_products')->assertJsonPath('data.fix_this_week', []);
        $this->getJson('/api/seller/black/visitor-insights?period=12')->assertStatus(422);
    }

    public function test_page_payload_ranks_fixes_by_lost_revenue_and_groups_by_stage(): void
    {
        foreach (range(1, 4) as $i) {
            $peer = $this->makeSeller('free');
            $p = $this->product(['price' => 60], $peer);
            $this->events($p, 'view', 40, 2);
            $this->events($p, 'cart_add', 4, 2);
            $this->sale($p, 2);
        }
        $cheap = $this->product(['price' => 40]);          // too few views → not enough data
        $this->events($cheap, 'view', 15, 2);   // above the visibility floor (35 % of 40), below 30
        $pricey = $this->product(['price' => 150]);        // price far above the median
        $this->events($pricey, 'view', 80, 2);
        $this->aggregate();
        app(FunnelBenchmarks::class)->build($this->today->subDay());

        Sanctum::actingAs($this->seller);
        $data = $this->getJson('/api/seller/black/visitor-insights?period=7')->assertOk()->json('data');
        $this->assertSame('ok', $data['state']);
        $this->assertSame($pricey->id, $data['fix_this_week'][0]['product']['id']);
        $this->assertSame('price_high', $data['fix_this_week'][0]['code']);
        $this->assertGreaterThan(0, $data['fix_this_week'][0]['lost_revenue']);
        $stages = collect($data['stages'])->keyBy('stage');
        $this->assertSame(1, $stages['product_page']['count']);
        $this->assertSame(1, $data['summary']['insufficient']);
        $this->assertSame(['search', 'category', 'home', 'sponsored', 'storefront', 'external', 'direct'], array_column($data['traffic']['sources'], 'source'));
        $kpi = collect($data['kpis'])->keyBy('key')['add_to_cart_rate'];
        $this->assertSame('category', $kpi['bench']['scope']);
        $this->assertArrayNotHasKey('revenue', $data['fix_this_week'][0], 'no sales reporting on this page');
    }

    public function test_applied_actions_are_recorded_from_the_flows_and_measured_before_after(): void
    {
        $p = $this->product();
        Sanctum::actingAs($this->seller);
        $this->postJson('/api/seller/black/visitor-insights/actions', ['product_id' => $p->id, 'kind' => 'edit', 'problem_code' => 'listing_quality', 'stage' => 'product_page'])
            ->assertCreated();
        $this->postJson('/api/seller/black/visitor-insights/actions', ['product_id' => $this->product([], $this->makeSeller('black'))->id, 'kind' => 'edit'])
            ->assertNotFound();

        $this->postJson('/api/seller/promotions', [
            'name' => 'Insight', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 10,
            'starts_at' => now()->addHour()->toDateTimeString(), 'ends_at' => now()->addDays(7)->toDateTimeString(),
            'product_ids' => [$p->id], 'insight_product' => $p->id, 'insight_problem' => 'price_high', 'insight_stage' => 'product_page',
        ])->assertCreated();
        $this->assertDatabaseHas('growth_actions', ['seller_id' => $this->seller->id, 'origin' => 'visitor_insights', 'product_id' => $p->id,
            'kind' => 'discount', 'problem_code' => 'price_high']);

        // An action 20 days ago with traffic on both sides gets a before / after
        DB::table('growth_actions')->insert(['seller_id' => $this->seller->id, 'origin' => 'visitor_insights', 'product_id' => $p->id,
            'kind' => 'edit', 'problem_code' => 'low_ctr', 'stage' => 'click', 'starts_at' => $this->today->subDays(20)->utc(),
            'ends_at' => $this->today->subDays(20)->utc(), 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([25 => 1, 15 => 6] as $ago => $carts) {
            $this->events($p, 'view', 40, $ago);
            $this->events($p, 'cart_add', $carts, $ago);
            app(FunnelAggregator::class)->aggregateDay($this->today->subDays($ago));
        }
        VisitorInsights::forget($this->seller->id);
        $actions = collect($this->getJson('/api/seller/black/visitor-insights')->json('data.actions'));
        $measured = $actions->firstWhere('status', 'measured');
        $this->assertNotNull($measured);
        $v2c = collect($measured['metrics'])->firstWhere('metric', 'view_to_cart');
        $this->assertEqualsWithDelta(0.025, $v2c['before'], 0.0001);
        $this->assertEqualsWithDelta(0.15, $v2c['after'], 0.0001);
        $this->assertSame('measuring', $actions->firstWhere('kind', 'discount')['status']);

        // Growth Radar's measurement never picks them up
        $this->assertCount(0, app(\App\Services\GrowthRadar\ResultMeasurer::class)->due(CarbonImmutable::now()->addMonth())
            ->where('seller_id', $this->seller->id));
    }

    // ── Other pages ─────────────────────────────────────────────────────────

    public function test_statistiques_shows_no_conversion_rate_on_a_tiny_sample(): void
    {
        $small = $this->product();
        DB::table('products')->where('id', $small->id)->update(['views' => 4]);
        $big = $this->product();
        DB::table('products')->where('id', $big->id)->update(['views' => 200]);
        Sanctum::actingAs($this->seller);
        $rows = collect($this->getJson('/api/seller/analytics/products')->assertOk()->json('data.products'))->keyBy('id');
        $this->assertNull($rows[$small->id]['conversion_rate']);
        $this->assertFalse($rows[$small->id]['conversion_sample_ok']);
        $this->assertNotNull($rows[$big->id]['conversion_rate']);
        $this->assertEquals(0, $rows[$big->id]['conversion_rate']);
    }

    public function test_listing_quality_tips_quote_the_products_own_numbers(): void
    {
        $a = $this->product(['description' => 'Courte description.', 'short_description' => null], null, 1);
        $b = $this->product([], null, 4);
        $quality = app(\App\Services\ProductQualityService::class)->analyze($this->seller->id);

        $labelsA = array_column($quality[$a->id]['tips'], 'label');
        $labelsB = array_column($quality[$b->id]['tips'], 'label');
        $this->assertNotEquals($labelsA, $labelsB);
        $this->assertTrue(collect($labelsA)->contains(fn ($l) => str_contains($l, '1 photo')));
        $this->assertTrue(collect($labelsA)->contains(fn ($l) => str_contains($l, '19 caractères')));
        $this->assertTrue(collect($labelsB)->contains(fn ($l) => str_contains($l, '4 photo')));
        $this->assertSame('photos', $quality[$a->id]['weakest']);
    }
}
