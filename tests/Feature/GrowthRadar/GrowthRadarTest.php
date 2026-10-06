<?php

namespace Tests\Feature\GrowthRadar;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\User;
use App\Notifications\Growth\TargetedCouponNotification;
use App\Services\CouponService;
use App\Services\GrowthRadar\GrowthRadar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Growth Radar: detectors, privacy floor, targeted coupons, tier gating, card lifecycle.
 * Disposable sellers / products / buyers only (choosetounsi_test).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/GrowthRadar/GrowthRadarTest.php
 */
class GrowthRadarTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private Category $category;
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.groq.key' => null, 'growth.ai_headlines' => false]);
        Notification::fake();
        Mail::fake();
        $this->today = GrowthRadar::today();
        $this->seller = $this->makeSeller('black');
        $s = Str::random(6);
        $this->category = Category::create(['name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s", 'slug' => "cat-$s", 'is_active' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(string $role, array $extra = []): User
    {
        $u = User::create([
            'name' => ucfirst($role) . ' ' . Str::random(5), 'email' => $role . '_' . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true, 'locale' => 'en',
        ]);
        if ($extra) DB::table('users')->where('id', $u->id)->update($extra);
        return $u->fresh();
    }

    private function makeSeller(string $plan): User
    {
        $u = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $u->id, 'full_name' => 'Growth Test', 'phone_number' => '20000000',
            'business_name' => 'Growth Shop ' . Str::random(4), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => $plan,
        ]);
        return $u;
    }

    private function product(array $attrs = [], ?User $seller = null): Product
    {
        $name = $attrs['name'] ?? 'Growth Product ' . Str::random(6);
        $listed = $attrs['created_at'] ?? $this->today->subDays(40)->utc();
        unset($attrs['created_at'], $attrs['name']);
        $p = Product::create($attrs + [
            'seller_id' => ($seller ?? $this->seller)->id, 'category_id' => $this->category->id, 'name' => $name,
            'slug' => Str::slug($name), 'price' => 50, 'stock' => 40, 'is_approved' => true, 'is_active' => true,
            'description' => str_repeat('Long enough description. ', 20),
        ]);
        DB::table('products')->where('id', $p->id)->update(['created_at' => $listed]);
        foreach (range(1, 3) as $i) {
            DB::table('product_images')->insert(['product_id' => $p->id, 'image_path' => "test/$p->id-$i.jpg", 'order' => $i, 'is_primary' => $i === 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $p->fresh();
    }

    /** $n events of a type on a product, spread over the last $days days. */
    private function events(Product $p, string $type, int $n, int $days = 20, ?int $userId = null): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'user_id' => $userId, 'session_id' => (string) Str::uuid(), 'product_id' => $p->id, 'seller_id' => $p->seller_id,
                'category_id' => $p->category_id, 'event_type' => $type,
                'created_at' => $this->today->subDays(1 + $i % $days)->setTime(10 + $i % 10, 0)->utc(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) DB::table('user_interactions')->insert($chunk);
    }

    private function sale(Product $p, int $daysAgo, int $qty = 1, ?User $buyer = null): void
    {
        $buyer ??= $this->makeUser('client');
        $at = $this->today->subDays($daysAgo)->setTime(12, 0)->utc();
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $buyer->id, 'order_number' => 'GR-' . Str::random(10), 'total_amount' => $p->price * $qty,
            'status' => 'delivered', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $soId = DB::table('seller_orders')->insertGetId([
            'order_id' => $orderId, 'seller_id' => $p->seller_id, 'status' => 'delivered',
            'subtotal' => $p->price * $qty, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'seller_order_id' => $soId, 'product_id' => $p->id, 'quantity' => $qty,
            'unit_price' => $p->price, 'price' => $p->price, 'total' => $p->price * $qty, 'net_total' => $p->price * $qty,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** $count other shops with 2 products each around $price in the test category, with some views. */
    private function peers(int $count, float $price = 50): void
    {
        for ($i = 0; $i < $count; $i++) {
            $peer = $this->makeSeller('free');
            foreach ([0.9, 1.1] as $k) {
                $p = $this->product(['price' => round($price * $k)], $peer);
                $this->events($p, 'view', 10);
            }
            $this->sale($p, 3);
        }
    }

    private function compute(?User $seller = null): array
    {
        return app(GrowthRadar::class)->compute(($seller ?? $this->seller)->id, null, true, $this->today);
    }

    private function cards(string $type, ?User $seller = null)
    {
        return DB::table('growth_cards')->where('seller_id', ($seller ?? $this->seller)->id)->where('type', $type)->where('status', 'new')->get();
    }

    // ── Price position + privacy floor ────────────────────────────────────

    public function test_overpriced_product_gets_a_discount_card_above_the_privacy_floor(): void
    {
        $this->peers(4);                                   // 4 peers + the seller = 5 shops
        $p = $this->product(['price' => 120]);
        $this->events($p, 'view', 60);

        $this->compute();
        $card = $this->cards('price_position')->first();
        $this->assertNotNull($card);
        $payload = json_decode($card->payload, true);
        $this->assertSame('discount', $payload['action']['kind']);
        $this->assertSame([$p->id], $payload['action']['product_ids']);
        $this->assertGreaterThanOrEqual(5, $payload['action']['discount_value']);
        $this->assertLessThanOrEqual(30, $payload['action']['discount_value']);
        $this->assertSame('high', $payload['params']['direction']);
    }

    public function test_no_cross_seller_price_stat_below_five_shops(): void
    {
        $this->peers(3);                                   // 4 shops only
        $p = $this->product(['price' => 120]);
        $this->events($p, 'view', 60);

        $this->compute();
        $this->assertCount(0, $this->cards('price_position'));
        $snap = DB::table('growth_snapshots')->where('seller_id', $this->seller->id)->first();
        $this->assertNull($snap->pricing);
    }

    // ── Own-data cards (no floor) ─────────────────────────────────────────

    public function test_leaking_product_without_market_data_uses_a_fallback_and_lowers_confidence(): void
    {
        $p = $this->product(['price' => 60, 'description' => 'Short']);
        $this->events($p, 'view', 120);

        $this->compute();
        $card = $this->cards('leaking_product')->first();
        $this->assertNotNull($card);
        $payload = json_decode($card->payload, true);
        $this->assertSame('description', $payload['params']['focus']);
        $this->assertSame('edit', $payload['action']['kind']);
        $this->assertSame('fallback', $payload['basis']['conversion']);
        $this->assertSame('low', $card->confidence);               // 120 views = medium, minus one level for the fallback
        $this->assertNotNull($card->impact_high);
        $this->assertLessThan($card->impact_high, $card->impact_low);
    }

    public function test_dead_stock_card_for_stock_that_has_not_moved(): void
    {
        $p = $this->product(['stock' => 30, 'created_at' => $this->today->subDays(200)->utc()]);
        $this->sale($p, 90);
        $this->events($p, 'view', 40);

        $this->compute();
        $card = $this->cards('dead_stock')->first();
        $this->assertNotNull($card);
        $payload = json_decode($card->payload, true);
        $this->assertSame('discount', $payload['action']['kind']);
        $this->assertSame(90, $payload['params']['days']);
    }

    // ── Warm audience + targeted coupons ──────────────────────────────────

    private function warmBuyers(Product $p, int $n): array
    {
        $buyers = [];
        for ($i = 0; $i < $n; $i++) {
            $b = $this->makeUser('client');
            DB::table('favorites')->insert(['user_id' => $b->id, 'product_id' => $p->id, 'created_at' => now()->subDays(3), 'updated_at' => now()]);
            $buyers[] = $b;
        }
        return $buyers;
    }

    public function test_warm_audience_needs_at_least_three_buyers(): void
    {
        $p = $this->product();
        $this->warmBuyers($p, 2);
        $this->compute();
        $this->assertCount(0, $this->cards('warm_audience'));

        $this->warmBuyers($p, 1);
        $this->compute();
        $card = $this->cards('warm_audience')->first();
        $this->assertNotNull($card);
        $this->assertSame(3, json_decode($card->payload, true)['params']['audience']);
    }

    public function test_targeted_coupon_is_private_to_the_audience_and_recorded(): void
    {
        $p = $this->product();
        $buyers = $this->warmBuyers($p, 3);
        $this->compute();
        $card = $this->cards('warm_audience')->first();

        Sanctum::actingAs($this->seller);
        $res = $this->postJson('/api/seller/coupons', [
            'code' => 'WARM' . Str::upper(Str::random(5)), 'discount_type' => 'percentage', 'discount_value' => 10,
            'usage_limit_per_customer' => 1, 'product_ids' => [$p->id], 'growth_card_id' => $card->id,
        ])->assertCreated()->assertJsonPath('data.audience_size', 3);

        $coupon = Coupon::find($res->json('data.id'));
        $this->assertNotNull($coupon->expires_at);
        $this->assertSame(3, DB::table('coupon_audiences')->where('coupon_id', $coupon->id)->count());
        Notification::assertSentTo($buyers[0], TargetedCouponNotification::class);
        $this->assertSame('applied', DB::table('growth_cards')->where('id', $card->id)->value('status'));
        $this->assertDatabaseHas('growth_actions', ['card_id' => $card->id, 'kind' => 'coupon', 'ref_id' => $coupon->id]);

        // The response never names the buyers
        $this->assertStringNotContainsString((string) $buyers[0]->email, $res->getContent());

        $service = app(CouponService::class);
        $items = [['product_id' => $p->id, 'quantity' => 1, 'line_total' => 50.0]];
        $this->assertTrue($service->validateForSeller($coupon->code, $this->seller->id, $buyers[0]->id, $items)['valid']);
        $stranger = $this->makeUser('client');
        $this->assertFalse($service->validateForSeller($coupon->code, $this->seller->id, $stranger->id, $items)['valid']);
    }

    public function test_marketing_email_only_with_consent(): void
    {
        $p = $this->product();
        $buyers = $this->warmBuyers($p, 3);
        DB::table('users')->where('id', $buyers[0]->id)->update(['marketing_emails_opt_in' => true, 'email_verified_at' => now()]);
        $this->compute();
        $card = $this->cards('warm_audience')->first();

        Sanctum::actingAs($this->seller);
        $this->postJson('/api/seller/coupons', [
            'code' => 'MAIL' . Str::upper(Str::random(5)), 'discount_type' => 'percentage', 'discount_value' => 10,
            'product_ids' => [$p->id], 'growth_card_id' => $card->id,
        ])->assertCreated();

        Mail::assertQueued(\App\Mail\Growth\TargetedCouponMail::class, 1);
        Mail::assertQueued(\App\Mail\Growth\TargetedCouponMail::class, fn ($m) => $m->user->id === $buyers[0]->id);
    }

    public function test_a_buyer_gets_at_most_one_targeted_coupon_a_week(): void
    {
        $p = $this->product();
        $buyers = $this->warmBuyers($p, 3);
        $this->compute();
        $card = $this->cards('warm_audience')->first();

        // Another shop already targeted one of these buyers this week
        $other = $this->makeSeller('black');
        $otherProduct = $this->product([], $other);
        $c = Coupon::create(['seller_id' => $other->id, 'code' => 'OTHER' . Str::random(5), 'discount_type' => 'percentage', 'discount_value' => 5, 'usage_count' => 0, 'is_active' => true]);
        $c->products()->attach($otherProduct->id);
        DB::table('coupon_audiences')->insert(['coupon_id' => $c->id, 'user_id' => $buyers[0]->id, 'created_at' => now()->subDays(2)]);

        Sanctum::actingAs($this->seller);
        $this->postJson('/api/seller/coupons', [
            'code' => 'CAP' . Str::upper(Str::random(5)), 'discount_type' => 'percentage', 'discount_value' => 10,
            'product_ids' => [$p->id], 'growth_card_id' => $card->id,
        ])->assertStatus(422)->assertJsonPath('code', 'AUDIENCE_TOO_SMALL');
        $this->assertDatabaseMissing('coupons', ['seller_id' => $this->seller->id]);   // rolled back, no public coupon left behind
    }

    // ── Hidden demand ─────────────────────────────────────────────────────

    public function test_hidden_demand_needs_thirty_searches(): void
    {
        $this->product(['name' => 'Chemise lin']);
        DB::table('search_missed_queries')->insert([
            'query' => 'caftan brode', 'day' => $this->today->subDays(2)->toDateString(), 'searches' => 29, 'results' => 0,
            'example' => 'caftan brodé', 'category_id' => $this->category->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->compute();
        $this->assertCount(0, $this->cards('hidden_demand'));

        DB::table('search_missed_queries')->where('query', 'caftan brode')->update(['searches' => 30]);
        $this->compute();
        $card = $this->cards('hidden_demand')->first();
        $this->assertNotNull($card);
        $this->assertSame('caftan brodé', json_decode($card->payload, true)['params']['query']);
    }

    // ── API: tiers, lifecycle, recording ──────────────────────────────────

    public function test_growth_radar_is_black_pepper_only(): void
    {
        $p = $this->product(['description' => 'Short']);
        $this->events($p, 'view', 120);
        Sanctum::actingAs($this->seller);
        $full = $this->getJson('/api/seller/growth-radar')->assertOk();
        $this->assertNotEmpty($full->json('data.cards.0.headline'));

        foreach (['red', 'free'] as $plan) {
            $other = $this->makeSeller($plan);
            $op = $this->product(['description' => 'Short'], $other);
            $this->events($op, 'view', 120);
            Sanctum::actingAs($other);
            $this->getJson('/api/seller/growth-radar')->assertForbidden()->assertJsonPath('code', 'PLAN_REQUIRED');
            $this->getJson('/api/seller/growth-radar/history')->assertForbidden();
            $this->postJson('/api/seller/growth-radar/refresh')->assertForbidden();
            $this->assertNotContains($other->id, app(GrowthRadar::class)->sellerIds());
        }
        $this->assertContains($this->seller->id, app(GrowthRadar::class)->sellerIds());
    }

    public function test_card_texts_follow_the_request_language(): void
    {
        $p = $this->product(['description' => 'Short', 'name' => 'Sac test']);
        $this->events($p, 'view', 120);
        Sanctum::actingAs($this->seller);
        $fr = $this->getJson('/api/seller/growth-radar', ['Accept-Language' => 'fr'])->json('data.cards.0');
        $en = $this->getJson('/api/seller/growth-radar', ['Accept-Language' => 'en'])->json('data.cards.0');
        $this->assertStringContainsString('vues', $fr['headline']);
        $this->assertStringContainsString('views', $en['headline']);
        $this->assertStringContainsString('description', $fr['recommendation']);
    }

    public function test_dismissed_card_does_not_come_back_and_snoozed_card_is_hidden(): void
    {
        $p = $this->product(['description' => 'Short']);
        $this->events($p, 'view', 120);
        $q = $this->product(['stock' => 30, 'created_at' => $this->today->subDays(200)->utc()]);
        $this->events($q, 'view', 40);
        $this->compute();
        $leak = $this->cards('leaking_product')->first();
        $dead = $this->cards('dead_stock')->first();

        Sanctum::actingAs($this->seller);
        $this->postJson("/api/seller/growth-radar/cards/{$leak->id}/dismiss")->assertOk();
        $this->postJson("/api/seller/growth-radar/cards/{$dead->id}/snooze", ['days' => 3])->assertOk();
        $this->postJson("/api/seller/growth-radar/cards/{$dead->id}/snooze", ['days' => 30])->assertStatus(422);

        $this->compute();
        $ids = collect($this->getJson('/api/seller/growth-radar')->json('data.cards'))->pluck('id')->all();
        $this->assertNotContains($leak->id, $ids);
        $this->assertNotContains($dead->id, $ids);
        $this->assertCount(0, $this->cards('leaking_product'));
        $this->assertSame('snoozed', DB::table('growth_cards')->where('id', $dead->id)->value('status'));
    }

    public function test_promotion_created_from_a_card_is_recorded(): void
    {
        $p = $this->product(['stock' => 30, 'created_at' => $this->today->subDays(200)->utc()]);
        $this->events($p, 'view', 40);
        $this->compute();
        $card = $this->cards('dead_stock')->first();
        $action = json_decode($card->payload, true)['action'];

        Sanctum::actingAs($this->seller);
        $res = $this->postJson('/api/seller/promotions', [
            'name' => 'Clearance', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => $action['discount_value'],
            'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(11)->toDateTimeString(),
            'product_ids' => [$p->id], 'growth_card_id' => $card->id,
        ])->assertCreated();

        $this->assertDatabaseHas('growth_actions', ['card_id' => $card->id, 'kind' => 'discount', 'ref_id' => $res->json('data.id'), 'product_id' => $p->id]);
        $this->assertSame('applied', DB::table('growth_cards')->where('id', $card->id)->value('status'));
    }

    public function test_another_sellers_card_id_is_ignored(): void
    {
        $other = $this->makeSeller('black');
        $op = $this->product(['stock' => 30, 'created_at' => $this->today->subDays(200)->utc()], $other);
        $this->events($op, 'view', 40);
        $this->compute($other);
        $card = $this->cards('dead_stock', $other)->first();

        $p = $this->product();
        Sanctum::actingAs($this->seller);
        $this->postJson('/api/seller/promotions', [
            'name' => 'Mine', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 10,
            'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(5)->toDateTimeString(),
            'product_ids' => [$p->id], 'growth_card_id' => $card->id,
        ])->assertCreated();
        $this->assertDatabaseMissing('growth_actions', ['card_id' => $card->id]);
        $this->assertSame('new', DB::table('growth_cards')->where('id', $card->id)->value('status'));
    }

    public function test_new_shop_gets_honest_unlock_hints_and_no_made_up_cards(): void
    {
        $this->product(['created_at' => $this->today->subDays(10)->utc()]);
        Sanctum::actingAs($this->seller);
        $res = $this->getJson('/api/seller/growth-radar')->assertOk();
        $keys = collect($res->json('data.score.unlocks'))->pluck('key')->all();
        $this->assertContains('add_products', $keys);
        $this->assertContains('more_views', $keys);
        $this->assertContains('first_sale', $keys);
        $this->assertSame([], $res->json('data.cards'));
    }

    public function test_old_ai_hub_and_auto_promote_routes_are_gone(): void
    {
        Sanctum::actingAs($this->seller);
        $this->getJson('/api/seller/black/ai-hub')->assertNotFound();
        $this->getJson('/api/seller/black/auto-promote-suggestions')->assertNotFound();
    }

    // ── Results (growth:measure) ──────────────────────────────────────────

    /** A 7-day 15 % discount that started $startAgo days ago, applied from a card. */
    private function appliedDiscount(Product $p, int $startAgo = 17, int $len = 7): object
    {
        $start = $this->today->subDays($startAgo)->setTime(9, 0);
        $promo = DB::table('promotions')->insertGetId([
            'seller_id' => $p->seller_id, 'name' => 'Radar test', 'type' => 'discount', 'discount_type' => 'percentage',
            'discount_value' => 15, 'status' => 'expired', 'starts_at' => $start->utc(), 'ends_at' => $start->addDays($len)->utc(),
            'priority' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('promotion_products')->insert(['promotion_id' => $promo, 'product_id' => $p->id]);
        $id = DB::table('growth_actions')->insertGetId([
            'seller_id' => $p->seller_id, 'product_id' => $p->id, 'kind' => 'discount', 'ref_id' => $promo, 'card_type' => 'leaking_product',
            'starts_at' => $start->utc(), 'ends_at' => $start->addDays($len)->utc(), 'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('growth_actions')->find($id);
    }

    private function promoSale(Product $p, int $daysAgo, int $promotionId): void
    {
        $this->sale($p, $daysAgo);
        DB::table('order_items')->where('product_id', $p->id)->orderByDesc('id')->limit(1)
            ->update(['promotion_id' => $promotionId, 'discount_amount' => 7.5, 'net_total' => 42.5, 'unit_price' => 42.5]);
    }

    private function measure(object $action): object
    {
        app(\App\Services\GrowthRadar\ResultMeasurer::class)->measure($action, false);
        return DB::table('growth_actions')->find($action->id);
    }

    public function test_a_clear_rise_that_pays_is_a_win_compared_with_the_same_days_before(): void
    {
        $p = $this->product(['created_at' => $this->today->subDays(90)->utc()]);
        $a = $this->appliedDiscount($p);
        foreach ([24, 21] as $ago) $this->sale($p, $ago);                                  // before: 2
        foreach ([16, 16, 15, 14, 13, 13, 12, 11, 11, 10] as $ago) $this->promoSale($p, $ago, $a->ref_id);  // during: 10
        foreach ([8, 6] as $ago) $this->sale($p, $ago);                                    // after: 2

        $row = $this->measure($a);
        $r = json_decode($row->result, true);
        $this->assertSame('measured', $row->status);
        $this->assertSame('win', $row->verdict);
        $this->assertSame(8, $r['days']);                                                  // 09:00 + 7 days touches 8 calendar days
        $this->assertSame(2, $r['baseline']['units']);
        $this->assertSame(10, $r['during']['units']);
        $this->assertSame(2, $r['after']['units']);
        $this->assertEqualsWithDelta(75.0, $r['discount_cost'], 0.001);                   // 10 × 7.5
        $this->assertEqualsWithDelta(425 - 100, $r['net_gain'], 0.001);                    // 10 × 42.5 − 2 × 50
        $this->assertEqualsWithDelta(500 - 100, $r['gross_gain'], 0.001);
        $this->assertCount(8 + 8 + 7, $r['daily']);                                       // before, during, after (capped at 7)
    }

    public function test_another_promotion_in_the_baseline_makes_it_unclear(): void
    {
        $p = $this->product(['created_at' => $this->today->subDays(90)->utc()]);
        $a = $this->appliedDiscount($p);
        $other = DB::table('promotions')->insertGetId([
            'seller_id' => $p->seller_id, 'name' => 'Earlier', 'type' => 'flash_sale', 'discount_type' => 'percentage', 'discount_value' => 20,
            'status' => 'expired', 'starts_at' => $this->today->subDays(22)->utc(), 'ends_at' => $this->today->subDays(21)->utc(),
            'priority' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('promotion_products')->insert(['promotion_id' => $other, 'product_id' => $p->id]);
        foreach ([16, 15, 14, 13, 12, 11, 10] as $ago) $this->promoSale($p, $ago, $a->ref_id);

        $row = $this->measure($a);
        $this->assertSame('unclear', $row->verdict);
        $this->assertContains('overlap', json_decode($row->result, true)['unclear']);
    }

    public function test_too_new_or_too_few_sales_is_unclear_never_a_win(): void
    {
        $p = $this->product(['created_at' => $this->today->subDays(20)->utc()]);   // listed after the baseline started
        $a = $this->appliedDiscount($p);
        foreach ([16, 15, 14] as $ago) $this->promoSale($p, $ago, $a->ref_id);

        $r = json_decode($this->measure($a)->result, true);
        $this->assertContains('short_history', $r['unclear']);
        $this->assertContains('few_sales', $r['unclear']);
    }

    public function test_more_sales_that_cost_more_than_they_brought_is_a_loss(): void
    {
        $p = $this->product(['created_at' => $this->today->subDays(90)->utc()]);
        $a = $this->appliedDiscount($p);
        foreach ([24, 23, 22, 21, 20, 19, 18, 18] as $ago) $this->sale($p, $ago);          // before: 8 × 50 = 400
        foreach (range(10, 16) as $ago) foreach ([1, 2, 3] as $_) $this->promoSale($p, $ago, $a->ref_id);  // during: 21 units
        DB::table('order_items')->where('promotion_id', $a->ref_id)->update(['net_total' => 15, 'discount_amount' => 35]);  // deep cut

        $row = $this->measure($a);
        $r = json_decode($row->result, true);
        $this->assertLessThan(0, $r['net_gain']);
        $this->assertSame('loss', $row->verdict);
    }

    public function test_measured_actions_feed_the_history_and_the_learning(): void
    {
        foreach ([0, 1] as $i) {
            $p = $this->product(['created_at' => $this->today->subDays(90)->utc()]);
            $a = $this->appliedDiscount($p);
            foreach ([24, 21] as $ago) $this->sale($p, $ago);
            foreach ([16, 16, 15, 14, 13, 13, 12, 11, 11, 10] as $ago) $this->promoSale($p, $ago, $a->ref_id);
            $this->measure($a);
        }
        $learning = \App\Services\GrowthRadar\Learning::forSeller($this->seller->id);
        $this->assertSame(2, $learning->byKind['discount']['n']);
        $this->assertGreaterThan(1.0, $learning->multiplier('discount'));

        Sanctum::actingAs($this->seller);
        $h = $this->getJson('/api/seller/growth-radar/history')->assertOk();
        $this->assertCount(2, $h->json('data.actions'));
        $this->assertSame('win', $h->json('data.actions.0.verdict'));
        $this->assertSame('discount', $h->json('data.learning.best'));
        $this->assertCount(2, $this->getJson('/api/seller/growth-radar')->json('data.results'));
    }

    public function test_actions_are_measured_only_after_the_waiting_period(): void
    {
        $p = $this->product();
        $recent = $this->appliedDiscount($p, 9);    // ended 2 days ago
        $old = $this->appliedDiscount($p, 17);      // ended 10 days ago
        $due = app(\App\Services\GrowthRadar\ResultMeasurer::class)->due()->pluck('id')->all();
        $this->assertContains($old->id, $due);
        $this->assertNotContains($recent->id, $due);
    }
}
