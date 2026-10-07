<?php

namespace Tests\Feature\Profit;

use App\Jobs\CheckGoalMilestones;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProfitAlertSetting;
use App\Models\RevenueGoal;
use App\Models\SellerApplication;
use App\Models\User;
use App\Notifications\ProfitGoalNotification;
use App\Services\Profit\GoalAlerts;
use App\Services\Profit\ProfitCenter;
use App\Services\Profit\SellerRevenueService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Centre de profit: revenue definitions (statuses, Tunis months, returns, ads),
 * projection, goals + validation, tier/ownership gating, idempotent alerts, CSV.
 * Disposable sellers / products / buyers only (choosetounsi_test).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Profit/ProfitCenterTest.php
 */
class ProfitCenterTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private User $buyer;
    private Product $product;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        // 20 Oct 2026, 12:00 in Tunis
        $this->freeze(CarbonImmutable::create(2026, 10, 20, 12, 0, 0, 'Africa/Tunis'));
        $s = Str::random(6);
        $this->category = Category::create(['name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s", 'slug' => "pc-cat-$s", 'is_active' => true]);
        $this->seller  = $this->makeSeller('black');
        $this->buyer   = $this->makeUser('client');
        $this->product = $this->product($this->seller);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function freeze(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at->utc());
        CarbonImmutable::setTestNow($at->utc());
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
            'user_id' => $u->id, 'full_name' => 'Profit Test', 'phone_number' => '20000000',
            'business_name' => 'Profit Shop ' . Str::random(4), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => $plan,
        ]);
        return $u;
    }

    private function product(User $seller): Product
    {
        $name = 'Profit Product ' . Str::random(6);
        return Product::create([
            'seller_id' => $seller->id, 'category_id' => $this->category->id, 'name' => $name,
            'slug' => Str::slug($name), 'price' => 100, 'stock' => 50, 'is_approved' => true, 'is_active' => true,
            'description' => 'Description', 'short_description' => 'Court',
        ]);
    }

    /** One sub-order of $amount DT (10 % commission) placed at a Tunis local time. */
    private function sale(float $amount, string $status, string $localAt, ?User $seller = null, ?Product $product = null, float $shipping = 0): int
    {
        $seller  = $seller ?? $this->seller;
        $product = $product ?? $this->product;
        $at = CarbonImmutable::parse($localAt, 'Africa/Tunis')->utc()->format('Y-m-d H:i:s');
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $this->buyer->id, 'order_number' => 'PC-' . Str::upper(Str::random(10)),
            'subtotal' => $amount, 'total_amount' => $amount, 'shipping_fee' => 0, 'status' => 'processing',
            'payment_status' => 'unpaid', 'payment_method' => 'cod', 'address' => 'Rue test', 'phone' => '20000000',
            'wilaya' => 'Tunis', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $commission = round($amount * 0.1, 3);
        $soId = DB::table('seller_orders')->insertGetId([
            'order_id' => $orderId, 'seller_id' => $seller->id, 'status' => $status, 'payment_status' => 'unpaid',
            'subtotal' => $amount, 'discount_amount' => 0, 'commission_amount' => $commission,
            'seller_net_amount' => $amount - $commission - $shipping, 'seller_shipping_charge' => $shipping,
            'delivery_fee' => 0, 'shipping_cost' => 0, 'platform_profit' => $commission, 'payout_status' => 'pending',
            'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'seller_order_id' => $soId, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => 1, 'price' => $amount, 'unit_price' => $amount, 'total' => $amount, 'discount_amount' => 0,
            'net_total' => $amount, 'commission_amount' => $commission, 'seller_amount' => $amount - $commission,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        return $soId;
    }

    private function center(): ProfitCenter
    {
        return app(ProfitCenter::class);
    }

    // ── Revenue definitions ─────────────────────────────────────────────────

    public function test_sales_count_confirmed_to_delivered_and_ignore_pending_and_cancelled(): void
    {
        $this->sale(100, 'delivered', '2026-10-02 10:00');
        $this->sale(50, 'confirmed', '2026-10-03 10:00');
        $this->sale(30, 'out_for_delivery', '2026-10-04 10:00');
        $this->sale(999, 'pending', '2026-10-05 10:00');
        $this->sale(777, 'cancelled', '2026-10-05 11:00');
        $this->sale(400, 'delivered', '2026-10-05 11:00', $this->makeSeller('black'));   // someone else

        $d = $this->center()->build($this->seller->id);

        $this->assertSame(180.0, $d['kpis']['sales']);
        $this->assertSame(100.0, $d['kpis']['delivered']);
        $this->assertSame(80.0, $d['kpis']['in_progress']);
        $this->assertSame(3, $d['kpis']['orders']);
        $this->assertSame(1, $d['kpis']['awaiting']['count']);
        $this->assertSame(999.0, $d['kpis']['awaiting']['amount']);
        $this->assertSame(162.0, $d['kpis']['net']);   // 180 − 10 % commission
    }

    public function test_month_boundaries_follow_tunis_time(): void
    {
        // 30 Sept 23:30 UTC = 1 Oct 00:30 in Tunis → October
        $this->sale(70, 'delivered', '2026-10-01 00:30');
        // 1 Oct 00:30 UTC... i.e. 30 Sept 23:59 Tunis → September
        $this->sale(40, 'delivered', '2026-09-30 23:59');

        $rev = app(SellerRevenueService::class);
        $m   = $rev->monthly($this->seller->id, $rev->monthStart('2026-09'), $rev->monthStart('2026-11'));

        $this->assertSame(70.0, $m['2026-10']['sales']);
        $this->assertSame(40.0, $m['2026-09']['sales']);
    }

    public function test_returns_ads_and_shipping_flow_into_the_breakdown(): void
    {
        $so = $this->sale(200, 'delivered', '2026-10-02 10:00', null, null, 7);
        // Full return: the listener cancels the sub-order and zeroes its commission
        DB::table('seller_orders')->where('id', $so)->update(['status' => 'cancelled', 'commission_amount' => 0, 'seller_shipping_charge' => 0]);
        DB::table('complaints')->insert([
            'user_id' => $this->buyer->id, 'order_id' => DB::table('seller_orders')->where('id', $so)->value('order_id'),
            'seller_id' => $this->seller->id, 'complaint_type' => 'damaged', 'resolution_type' => 'return_refund',
            'description' => 'Cassé', 'status' => 'approved', 'refund_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sale(100, 'delivered', '2026-10-03 10:00', null, null, 5);
        DB::table('ad_wallet_transactions')->insert([
            ['seller_id' => $this->seller->id, 'type' => 'click_charge', 'amount' => -4.5, 'credit_amount' => -2, 'balance_after' => 0,
             'credit_after' => 0, 'rollup_date' => '2026-10-10', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $c = $this->center()->build($this->seller->id)['breakdown']['current'];

        $this->assertSame(100.0, $c['sales']);
        $this->assertSame(200.0, $c['refunds']);
        $this->assertSame(300.0, $c['gross']);
        $this->assertSame(10.0, $c['commission']);
        $this->assertSame(5.0, $c['shipping']);
        $this->assertSame(4.5, $c['ads']);
        $this->assertSame(2.0, $c['ads_credit']);
        $this->assertSame(80.5, $c['net']);   // 100 − 10 − 5 − 4.5
    }

    // ── Projection & suggestion ─────────────────────────────────────────────

    public function test_projection_blends_history_instead_of_showing_zero(): void
    {
        foreach (['2026-07-10', '2026-08-10', '2026-09-10'] as $d) $this->sale(900, 'delivered', "$d 10:00");

        $d = $this->center()->build($this->seller->id);   // no October sale yet

        $this->assertSame(0.0, $d['kpis']['sales']);
        $this->assertNotNull($d['projection']);
        $this->assertGreaterThan(0, $d['projection']['amount']);
        $this->assertSame('low', $d['projection']['confidence']);
        $this->assertSame([ 'prudent' => 860.0, 'realistic' => 990.0, 'ambitious' => 1150.0 ], $d['suggestion']['presets']);
    }

    public function test_projection_is_null_without_any_data(): void
    {
        $d = $this->center()->build($this->seller->id);
        $this->assertNull($d['projection']);
        $this->assertSame('new', $d['state']);
        $this->assertSame('starter', $d['suggestion']['basis']);
    }

    public function test_goal_progress_pace_and_orders_needed(): void
    {
        RevenueGoal::create(['seller_id' => $this->seller->id, 'month' => '2026-10', 'goal_amount' => 1000]);
        for ($i = 1; $i <= 4; $i++) $this->sale(100, 'delivered', "2026-10-0$i 10:00");

        $p = $this->center()->build($this->seller->id)['progress'];

        $this->assertSame(40.0, $p['pct']);
        $this->assertSame(600.0, $p['remaining']);
        $this->assertSame(6, $p['orders_needed']);          // 600 / 100 DT basket
        $this->assertSame(50.0, $p['required_daily']);      // 600 / 12 days (20 → 31 included)
        $this->assertContains($p['status'], ['on_track', 'ahead', 'behind']);
    }

    public function test_streak_counts_consecutive_months_hit(): void
    {
        foreach (['2026-07', '2026-08', '2026-09'] as $m) {
            RevenueGoal::create(['seller_id' => $this->seller->id, 'month' => $m, 'goal_amount' => 100]);
            $this->sale(150, 'delivered', "$m-10 10:00");
        }
        RevenueGoal::create(['seller_id' => $this->seller->id, 'month' => '2026-06', 'goal_amount' => 500]);   // missed

        $s = $this->center()->build($this->seller->id)['streak'];

        $this->assertSame(3, $s['current']);
        $this->assertSame(3, $s['best']);
        $this->assertContains('streak_3', $s['badges']);
        $this->assertContains('overachiever', $s['badges']);
    }

    // ── API: gating, ownership, validation ──────────────────────────────────

    public function test_only_black_sellers_reach_the_profit_center(): void
    {
        Sanctum::actingAs($this->makeSeller('green'));
        $this->getJson('/api/seller/black/profit-center')->assertStatus(403);

        Sanctum::actingAs($this->makeUser('client'));
        $this->getJson('/api/seller/black/profit-center')->assertStatus(403);
    }

    public function test_a_seller_only_sees_and_edits_their_own_goal(): void
    {
        $other = $this->makeSeller('black');
        RevenueGoal::create(['seller_id' => $other->id, 'month' => '2026-10', 'goal_amount' => 5000]);
        $this->sale(300, 'delivered', '2026-10-02 10:00', $other, $this->product($other));

        Sanctum::actingAs($this->seller);
        $this->getJson('/api/seller/black/profit-center')->assertOk()
            ->assertJsonPath('data.goal', null)
            ->assertJsonPath('data.kpis.sales', 0);

        $this->postJson('/api/seller/black/revenue-goals', ['month' => '2026-10', 'amount' => 800])->assertOk();
        $this->deleteJson('/api/seller/black/revenue-goals/2026-10')->assertOk();
        $this->assertSame(5000.0, RevenueGoal::where('seller_id', $other->id)->value('goal_amount'));
    }

    public function test_goal_validation(): void
    {
        Sanctum::actingAs($this->seller);
        $this->postJson('/api/seller/black/revenue-goals', ['month' => '2026-09', 'amount' => 500])->assertStatus(422);
        $this->postJson('/api/seller/black/revenue-goals', ['month' => '2026-10', 'amount' => 0])->assertStatus(422);
        $this->postJson('/api/seller/black/revenue-goals', ['month' => '2026-10', 'amount' => 500, 'net_target' => 600])->assertStatus(422);
        $this->postJson('/api/seller/black/revenue-goals', ['month' => '2026-11', 'amount' => 500, 'orders_target' => 10, 'net_target' => 400, 'preset' => 'realistic'])
            ->assertOk()->assertJsonPath('data.orders_target', 10);
    }

    public function test_editing_a_goal_silences_thresholds_already_passed(): void
    {
        $this->sale(600, 'delivered', '2026-10-02 10:00');
        Sanctum::actingAs($this->seller);
        $this->postJson('/api/seller/black/revenue-goals', ['month' => '2026-10', 'amount' => 1000])->assertOk()
            ->assertJsonPath('data.milestones_sent', [25, 50]);
    }

    // ── Alerts ──────────────────────────────────────────────────────────────

    public function test_milestones_fire_once_from_order_status_changes(): void
    {
        Notification::fake();
        RevenueGoal::create(['seller_id' => $this->seller->id, 'month' => '2026-10', 'goal_amount' => 400, 'milestones_sent' => []]);
        $so = $this->sale(220, 'pending', '2026-10-05 10:00');

        // Seller confirms: 55 % → one alert for 50 % (25 % is marked, not sent separately)
        \App\Models\SellerOrder::find($so)->update(['status' => 'confirmed']);
        (new CheckGoalMilestones($this->seller->id))->handle(app(GoalAlerts::class));
        (new CheckGoalMilestones($this->seller->id))->handle(app(GoalAlerts::class));

        Notification::assertSentToTimes($this->seller, ProfitGoalNotification::class, 1);
        Notification::assertSentTo($this->seller, ProfitGoalNotification::class, fn($n) => $n->event === 'milestone' && $n->params['pct'] === 50);
        $this->assertSame([25, 50], RevenueGoal::where('seller_id', $this->seller->id)->first()->milestones_sent);
    }

    public function test_alerts_respect_the_toggle_and_channels(): void
    {
        Notification::fake();
        RevenueGoal::create(['seller_id' => $this->seller->id, 'month' => '2026-10', 'goal_amount' => 100]);
        ProfitAlertSetting::create(['seller_id' => $this->seller->id, 'enabled' => false]);
        $this->sale(150, 'delivered', '2026-10-05 10:00');

        $this->assertNull(app(GoalAlerts::class)->checkMilestones($this->seller->id));
        Notification::assertNothingSent();

        Sanctum::actingAs($this->seller);
        $this->putJson('/api/seller/black/profit-center/alerts', ['enabled' => true, 'channel_email' => true])->assertOk()
            ->assertJsonPath('data.channel_email', true);
        $this->assertSame(100, app(GoalAlerts::class)->checkMilestones($this->seller->id));
        Notification::assertSentTo($this->seller, ProfitGoalNotification::class,
            fn($n, $channels) => $channels === ['database', 'mail']);
    }

    public function test_pace_alert_has_a_cooldown(): void
    {
        Notification::fake();
        RevenueGoal::create(['seller_id' => $this->seller->id, 'month' => '2026-10', 'goal_amount' => 5000]);
        $this->sale(100, 'delivered', '2026-10-02 10:00');
        $alerts = app(GoalAlerts::class);

        $this->assertTrue($alerts->checkPace($this->seller->id));
        $this->assertFalse($alerts->checkPace($this->seller->id));   // within 3 days
        $this->freeze(CarbonImmutable::now()->addDays(3)->addHour());
        $this->assertTrue($alerts->checkPace($this->seller->id));
        Notification::assertSentToTimes($this->seller, ProfitGoalNotification::class, 2);
    }

    public function test_monthly_recap_snapshots_and_reminds_once(): void
    {
        Notification::fake();
        $this->freeze(CarbonImmutable::create(2026, 11, 1, 9, 0, 0, 'Africa/Tunis'));
        RevenueGoal::create(['seller_id' => $this->seller->id, 'month' => '2026-10', 'goal_amount' => 300]);
        $this->sale(350, 'delivered', '2026-10-15 10:00');

        $this->artisan('profit:goal-alerts', ['--task' => 'monthly', '--seller' => $this->seller->id])->assertExitCode(0);
        $this->artisan('profit:goal-alerts', ['--task' => 'monthly', '--seller' => $this->seller->id])->assertExitCode(0);

        $goal = RevenueGoal::where('seller_id', $this->seller->id)->where('month', '2026-10')->first();
        $this->assertSame(350.0, $goal->achieved_revenue);
        $this->assertNotNull($goal->closed_at);
        Notification::assertSentToTimes($this->seller, ProfitGoalNotification::class, 2);   // recap + new goal, once each
        Notification::assertSentTo($this->seller, ProfitGoalNotification::class, fn($n) => $n->event === 'recap' && $n->params['hit'] === true);
        Notification::assertSentTo($this->seller, ProfitGoalNotification::class, fn($n) => $n->event === 'new_goal');
    }

    public function test_notification_texts_are_translated(): void
    {
        $n = new ProfitGoalNotification('milestone', ['month' => '2026-10', 'pct' => 50, 'sales' => 1234.5, 'goal' => 2469, 'remaining' => 1234.5, 'days_left' => 12], ['bell']);
        app()->setLocale('fr');
        $this->assertStringContainsString("1\u{202F}234,50 DT", $n->body());
        app()->setLocale('ar');
        $this->assertStringContainsString('د.ت', $n->body());
        app()->setLocale('en');
        $this->assertSame('50% of your goal reached', $n->title());
        $this->assertSame('/seller/black/profit', $n->toDatabase($this->seller)['link']);
    }

    public function test_csv_export(): void
    {
        $this->sale(120, 'delivered', '2026-10-02 10:00');
        Sanctum::actingAs($this->seller);
        $res = $this->get('/api/seller/black/profit-center/export?month=2026-10', ['Accept-Language' => 'fr'])->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('"Gains nets";108,000', $csv);
        $this->get('/api/seller/black/profit-center/export?month=2020-01')->assertStatus(302);   // not JSON: validation redirect
    }
}
