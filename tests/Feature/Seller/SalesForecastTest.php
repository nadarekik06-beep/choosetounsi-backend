<?php

namespace Tests\Feature\Seller;

use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\User;
use App\Notifications\ForecastAlertNotification;
use App\Services\Forecast\EventEffectMeasurer;
use App\Services\Forecast\ForecastAlerts;
use App\Services\Forecast\ForecastService;
use App\Services\Forecast\SalesSeries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sales forecast: real-sales series, API, refresh limit, plan gate, alerts.
 * Disposable sellers/products/buyers only (choosetounsi_test).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/SalesForecastTest.php
 */
class SalesForecastTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private User $buyer;
    private Category $category;
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.groq.key' => null]);   // never call Groq from tests
        $this->today    = CarbonImmutable::now(config('forecast.timezone'))->startOfDay();
        $this->seller   = $this->makeSeller('red');
        $this->buyer    = $this->makeUser('client');
        $s = Str::random(6);
        $this->category = Category::create(['name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s", 'slug' => "cat-$s", 'is_active' => true]);
    }

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
            'user_id' => $u->id, 'full_name' => 'Forecast Test', 'phone_number' => '20000000',
            'business_name' => 'Forecast Shop ' . Str::random(4), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => $plan,
        ]);
        return $u;
    }

    private function product(array $attrs = [], ?User $seller = null): Product
    {
        $name = 'Forecast Product ' . Str::random(6);
        $listed = $attrs['created_at'] ?? $this->today->subDays(200)->utc();
        unset($attrs['created_at']);
        $p = Product::create($attrs + [
            'seller_id' => ($seller ?? $this->seller)->id, 'category_id' => $this->category->id, 'name' => $name,
            'slug' => Str::slug($name), 'price' => 50, 'stock' => 40, 'is_approved' => true, 'is_active' => true,
        ]);
        DB::table('products')->where('id', $p->id)->update(['created_at' => $listed]);   // not fillable
        return $p->fresh();
    }

    /** One order with one item, placed $daysAgo days ago (local noon). Returns the order_item id. */
    private function sale(Product $p, int $daysAgo, int $qty = 1, string $status = 'delivered', ?int $promotionId = null, ?User $seller = null): int
    {
        $at = $this->today->subDays($daysAgo)->setTime(12, 0)->utc();
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $this->buyer->id, 'order_number' => 'FC-' . Str::random(10), 'total_amount' => 50 * $qty,
            'status' => $status, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $soId = DB::table('seller_orders')->insertGetId([
            'order_id' => $orderId, 'seller_id' => ($seller ?? $this->seller)->id, 'status' => $status,
            'subtotal' => 50 * $qty, 'created_at' => $at, 'updated_at' => $at,
        ]);
        return DB::table('order_items')->insertGetId([
            'order_id' => $orderId, 'seller_order_id' => $soId, 'product_id' => $p->id, 'quantity' => $qty,
            'unit_price' => 50, 'price' => 50, 'total' => 50 * $qty, 'net_total' => 45 * $qty,
            'promotion_id' => $promotionId, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function series(Product $p): array
    {
        app(SalesSeries::class)->rebuild($this->seller->id, $this->today);
        return app(SalesSeries::class)->load($this->seller->id)[$p->id];
    }

    // ── Data foundation ─────────────────────────────────────────────────────

    public function test_only_confirmed_shipped_and_delivered_sales_count(): void
    {
        $p = $this->product();
        $this->sale($p, 5, 2, 'delivered');
        $this->sale($p, 5, 1, 'confirmed');
        $this->sale($p, 5, 1, 'out_for_delivery');
        $this->sale($p, 5, 4, 'cancelled');
        $this->sale($p, 5, 3, 'refunded');
        $this->sale($p, 5, 5, 'pending');

        $day = $this->today->subDays(5)->toDateString();
        $row = $this->series($p)['daily'][$day];
        $this->assertSame(4, $row['units']);
        $this->assertSame(3, $row['orders']);
        $this->assertEqualsWithDelta(4 * 45.0, $row['net_revenue'], 0.001);   // net of discounts
    }

    public function test_approved_returns_are_netted_out(): void
    {
        $p = $this->product();
        $kept     = $this->sale($p, 10, 1);
        $returned = $this->sale($p, 10, 2);
        $orderId  = DB::table('order_items')->where('id', $returned)->value('order_id');
        DB::table('complaints')->insert([
            'user_id' => $this->buyer->id, 'order_id' => $orderId, 'seller_id' => $this->seller->id,
            'order_item_ids' => json_encode([$returned]), 'complaint_type' => 'damaged', 'description' => 'x',
            'resolution_type' => 'return_refund', 'status' => 'refunded', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->series($p)['daily'][$this->today->subDays(10)->toDateString()]['units']);
    }

    public function test_promotion_sales_and_days_are_flagged(): void
    {
        $p = $this->product();
        $promoId = DB::table('promotions')->insertGetId([
            'seller_id' => $this->seller->id, 'name' => 'Flash', 'type' => 'flash_sale', 'discount_type' => 'percentage',
            'discount_value' => 20, 'status' => 'expired',
            'starts_at' => $this->today->subDays(8)->utc(), 'ends_at' => $this->today->subDays(6)->endOfDay()->utc(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('promotion_products')->insert(['promotion_id' => $promoId, 'product_id' => $p->id]);
        $this->sale($p, 7, 6, 'delivered', $promoId);
        $this->sale($p, 20, 1);

        $daily = $this->series($p)['daily'];
        $this->assertSame(6, $daily[$this->today->subDays(7)->toDateString()]['promo_units']);
        $this->assertTrue($daily[$this->today->subDays(8)->toDateString()]['promo_day']);
        $this->assertFalse($daily[$this->today->subDays(20)->toDateString()]['promo_day']);
    }

    public function test_variant_sales_and_stock_are_tracked(): void
    {
        $p = $this->product(['stock' => 0]);
        $vM = DB::table('product_variants')->insertGetId(['product_id' => $p->id, 'stock' => 4, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $vL = DB::table('product_variants')->insertGetId(['product_id' => $p->id, 'stock' => 9, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $item = $this->sale($p, 3, 2);
        DB::table('order_items')->where('id', $item)->update(['variant_id' => $vM]);

        $s = $this->series($p);
        $this->assertSame(13, $s['stock']);                    // sum of active variants
        $this->assertSame(2, $s['variants'][$vM]['daily'][$this->today->subDays(3)->toDateString()]);
        $this->assertSame([], $s['variants'][$vL]['daily']);
    }

    // ── API ─────────────────────────────────────────────────────────────────

    public function test_new_seller_gets_no_invented_numbers(): void
    {
        $p = $this->product(['created_at' => $this->today->subDays(3)->utc()]);
        Sanctum::actingAs($this->seller);

        $res = $this->getJson("/api/seller/forecast?product_id={$p->id}")->assertOk();
        $f = $res->json('data.forecast');
        $this->assertSame('insufficient', $f['tier']);
        $this->assertNull($f['next28']);
        $this->assertSame([], $f['weeks']);
        $this->assertContains('no_data', array_column($f['actions'], 'type'));
        $this->assertSame('insufficient', $res->json('data.products.0.tier'));
    }

    public function test_forecast_is_served_from_snapshot_and_refresh_is_rate_limited(): void
    {
        $p = $this->product(['stock' => 6]);
        for ($d = 1; $d <= 120; $d += 2) $this->sale($p, $d, 1);
        Sanctum::actingAs($this->seller);
        RateLimiter::clear("forecast-refresh:{$this->seller->id}");

        $first = $this->getJson("/api/seller/forecast?product_id={$p->id}")->assertOk()->json('data.forecast');
        $this->assertSame('own', $first['tier']);
        $this->assertNotNull($first['next28']);
        $this->assertSame(1, DB::table('forecast_snapshots')->where('product_id', $p->id)->where('variant_id', 0)->count());

        // Second read does not recompute
        $before = DB::table('forecast_snapshots')->where('product_id', $p->id)->value('updated_at');
        $this->getJson("/api/seller/forecast?product_id={$p->id}")->assertOk();
        $this->assertSame($before, DB::table('forecast_snapshots')->where('product_id', $p->id)->value('updated_at'));

        $this->postJson('/api/seller/forecast/refresh', ['product_id' => $p->id])->assertOk();
        $this->postJson('/api/seller/forecast/refresh', ['product_id' => $p->id])
            ->assertStatus(429)->assertJson(['code' => 'REFRESH_COOLDOWN']);
    }

    public function test_explanation_falls_back_to_template_without_groq(): void
    {
        $p = $this->product();
        for ($d = 1; $d <= 100; $d += 3) $this->sale($p, $d, 1);
        Sanctum::actingAs($this->seller);
        $this->getJson("/api/seller/forecast?product_id={$p->id}")->assertOk();

        foreach (['fr', 'en', 'ar'] as $locale) {
            $data = $this->getJson("/api/seller/forecast/explain?product_id={$p->id}&locale={$locale}")->assertOk()->json('data');
            $this->assertSame('template', $data['source']);
            $this->assertNotEmpty($data['text']);
            $this->assertStringNotContainsString('forecast.ai.', $data['text']);
        }
    }

    public function test_other_sellers_products_and_free_plan_are_refused(): void
    {
        $other = $this->makeSeller('red');
        $foreign = $this->product([], $other);
        Sanctum::actingAs($this->seller);
        $this->getJson("/api/seller/forecast?product_id={$foreign->id}")->assertNotFound();

        Sanctum::actingAs($this->makeSeller('free'));
        $this->getJson('/api/seller/forecast')->assertForbidden();
    }

    public function test_settings_change_reorder_dates(): void
    {
        $p = $this->product(['stock' => 30]);
        for ($d = 1; $d <= 120; $d++) $this->sale($p, $d, 1);
        Sanctum::actingAs($this->seller);

        $short = $this->putJson('/api/seller/forecast/settings', ['lead_time_days' => 2, 'safety_days' => 0, 'view_product_id' => $p->id])
            ->assertOk()->json('data.forecast.stock');
        $long = $this->putJson('/api/seller/forecast/settings', ['lead_time_days' => 20, 'safety_days' => 5, 'view_product_id' => $p->id])
            ->assertOk()->json('data.forecast.stock');
        $this->assertSame(20, $long['lead_time_days']);
        $this->assertLessThan($short['reorder_by'], $long['reorder_by']);
        $this->assertGreaterThan($short['reorder_qty'], $long['reorder_qty']);
    }

    // ── Calendar & alerts ───────────────────────────────────────────────────

    public function test_event_effect_is_measured_with_its_sample_size(): void
    {
        $p = $this->product();
        $start = $this->today->subDays(60);
        $event = CalendarEvent::create([
            'key' => 'test_event_' . Str::random(4), 'name_fr' => 'Test', 'name_en' => 'Test', 'name_ar' => 'Test',
            'starts_on' => $start->toDateString(), 'ends_on' => $start->addDays(6)->toDateString(), 'is_active' => true,
        ]);
        for ($d = 0; $d < 7; $d++) $this->sale($p, 60 - $d, 2);                       // 2/day during the event
        foreach ([70, 75, 80, 45, 40, 35, 30] as $d) $this->sale($p, $d, 1);          // sparse around it

        app(EventEffectMeasurer::class)->measure($event);
        $effect = $event->effects()->where('category_id', $this->category->id)->first();
        $this->assertNotNull($effect);
        $this->assertSame(7, $effect->event_orders);
        $this->assertSame(7, $effect->baseline_orders);
        $this->assertGreaterThan(500, $effect->change_pct);   // 2/day vs 7 units over 56 days
        $this->assertFalse($effect->isReliable());            // 7 orders < 30: reminder only, never in the math
    }

    public function test_admin_calendar_crud_and_hijri_dates(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $year = 2031;
        DB::table('calendar_events')->whereYear('starts_on', $year)->delete();

        $created = $this->postJson('/api/admin/calendar-events/generate-hijri', ['year' => $year])->assertOk()->json('created');
        $this->assertGreaterThanOrEqual(4, $created);
        $this->assertSame(0, $this->postJson('/api/admin/calendar-events/generate-hijri', ['year' => $year])->json('created'));

        $id = $this->postJson('/api/admin/calendar-events', [
            'key' => 'rentree', 'name_fr' => 'Rentrée scolaire', 'name_en' => 'Back to school', 'name_ar' => 'العودة المدرسية',
            'starts_on' => "$year-09-10", 'ends_on' => "$year-09-20", 'category_ids' => [$this->category->id],
            'boost_score' => 1.8,   // ignored: there is no uplift field
        ])->assertCreated()->json('data.id');

        $list = $this->getJson("/api/admin/calendar-events?year=$year")->assertOk()->json('data');
        $row = collect($list)->firstWhere('id', $id);
        $this->assertSame([$this->category->id], $row['category_ids']);
        $this->assertArrayNotHasKey('boost_score', $row);
        $this->assertSame('hijri', collect($list)->firstWhere('key', 'ramadan')['source']);

        $this->putJson("/api/admin/calendar-events/$id", ['key' => 'rentree', 'name_fr' => 'Rentrée', 'name_en' => 'Back to school',
            'name_ar' => 'العودة المدرسية', 'starts_on' => "$year-09-12", 'ends_on' => "$year-09-11"])->assertStatus(422);
        $this->deleteJson("/api/admin/calendar-events/$id")->assertOk();

        Sanctum::actingAs($this->seller);
        $this->getJson("/api/admin/calendar-events?year=$year")->assertForbidden();
    }

    public function test_stockout_alert_is_sent_once_and_respects_opt_out(): void
    {
        Notification::fake();
        $p = $this->product(['stock' => 3]);
        for ($d = 1; $d <= 120; $d++) $this->sale($p, $d, 1);
        $service = app(ForecastService::class);
        $alerts  = app(ForecastAlerts::class);

        $computed = $service->computeSeller($this->seller->id, $this->today);
        $this->assertSame(1, $alerts->process($this->seller->id, $computed, $service->settings($this->seller->id), $this->today));
        $this->assertSame(0, $alerts->process($this->seller->id, $computed, $service->settings($this->seller->id), $this->today));
        Notification::assertSentToTimes($this->seller, ForecastAlertNotification::class, 1);
        Notification::assertSentTo($this->seller, ForecastAlertNotification::class, fn($n) => $n->type === 'stockout');

        $service->saveSettings($this->seller->id, ['alerts_enabled' => false]);
        DB::table('forecast_alert_log')->where('seller_id', $this->seller->id)->delete();
        $this->assertSame(0, $alerts->process($this->seller->id, $computed, $service->settings($this->seller->id), $this->today));
    }
}
