<?php

namespace Tests\Feature\Ads;

use App\Jobs\GenerateAdCopy;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Ads\AdEventService;
use App\Services\Ads\AdMetrics;
use App\Services\Ads\AdTokenService;
use App\Services\Ads\AttributionService;
use App\Services\Ads\SponsorshipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The numbers sellers see on their ads pages, end to end: impression → click →
 * duplicate click → fraud clicks → orders → cancelled order → delivered order,
 * across two sellers and three campaigns, plus an Africa/Tunis midnight boundary.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/AdResultsAccuracyTest.php
 */
class AdResultsAccuracyTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    private AdEventService $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        Notification::fake();
        Bus::fake([GenerateAdCopy::class]);
        $this->events = app(AdEventService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_seller_results_match_what_really_happened(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        $cat = $this->makeCategory();
        $a = $this->seller();
        $b = $this->seller();
        $c1 = $this->campaign($a, $this->readyProduct($a, $cat, ['price' => 50]));
        $c2 = $this->campaign($a, $this->readyProduct($a, $cat, ['price' => 30]));
        $c3 = $this->campaign($b, $this->readyProduct($b, $cat, ['price' => 20]));
        [$x, $y, $z, $w] = [$this->makeUser(), $this->makeUser(), $this->makeUser(), $this->makeUser()];

        // Impression, then the same one again (deduplicated).
        $this->assertTrue($this->events->record($this->token($c1), 'impression', $this->viewer($x))['accepted']);
        $this->assertFalse($this->events->record($this->token($c1), 'impression', $this->viewer($x))['accepted']);

        // A paid click, a repeat 5 minutes later, a bot and the seller's own click.
        $this->assertTrue($this->click($c1, $x)['billable']);
        Carbon::setTestNow('2026-09-30 10:05:00');
        $this->assertSame('duplicate', $this->click($c1, $x)['reason']);
        $this->assertSame('bot', $this->events->record($this->token($c1), 'click',
            ['user_id' => null, 'session_id' => null, 'ip' => '6.6.6.6', 'user_agent' => 'Googlebot/2.1'])['reason']);
        $this->assertSame('self_click', $this->click($c1, $a)['reason']);

        $this->assertTrue($this->click($c2, $y)['billable']);
        $this->assertTrue($this->click($c3, $z)['billable']);
        $this->assertTrue($this->click($c1, $w)['billable']);
        $this->assertTrue($this->click($c2, $w)['billable']);

        // Orders. X: promoted P1 ×2 + an unpromoted line (not credited).
        Carbon::setTestNow('2026-09-30 11:00:00');
        $o1 = $this->order($x, [[$c1->product, 2], [$c2->product, 1]]);
        // Y buys P2, then that seller's part is cancelled.
        $o2 = $this->order($y, [[$c2->product, 1]]);
        DB::table('seller_orders')->where('order_id', $o2->id)->update(['status' => 'cancelled']);
        // X again, delivered later (status written with the query builder, like the app does).
        $o3 = $this->order($x, [[$c1->product, 1]]);
        Order::where('id', $o3->id)->update(['status' => 'delivered']);
        DB::table('seller_orders')->where('order_id', $o3->id)->update(['status' => 'delivered']);
        // W: one order touching both of A's campaigns — one order for A's overview.
        $o4 = $this->order($w, [[$c1->product, 1], [$c2->product, 1]]);
        // Z clicked B's ad but buys nothing.

        // Campaign 1: clicks X + W; spend 2 × 0.400; orders o1 (100), o3 (50), o4 (50).
        Sanctum::actingAs($a);
        $this->getJson("/api/seller/ads/campaigns/{$c1->id}")->assertOk()
            ->assertJsonPath('data.summary.impressions', 1)
            ->assertJsonPath('data.summary.clicks', 2)
            ->assertJsonPath('data.summary.spend', 0.8)
            ->assertJsonPath('data.summary.orders', 3)
            ->assertJsonPath('data.summary.revenue', 200)
            ->assertJsonPath('data.summary.roas', 250)
            ->assertJsonPath('data.summary.cost_per_order', 0.267)
            ->assertJsonPath('data.stats.clicks', 2)
            ->assertJsonPath('data.stats.orders', 3)
            ->assertJsonCount(30, 'data.daily')
            ->assertJsonPath('data.daily.29.date', '2026-09-30')
            ->assertJsonPath('data.daily.29.clicks', 2)
            ->assertJsonPath('data.daily.29.cost', 0.8)
            ->assertJsonPath('data.daily.29.orders', 3)
            ->assertJsonPath('data.placement_stats.0.placement', 'home_row')
            ->assertJsonPath('data.placement_stats.0.clicks', 2);

        // Campaign 2: clicks Y + W; o2 is cancelled → only o4 (30).
        $this->getJson("/api/seller/ads/campaigns/{$c2->id}")->assertOk()
            ->assertJsonPath('data.summary.clicks', 2)
            ->assertJsonPath('data.summary.orders', 1)
            ->assertJsonPath('data.summary.revenue', 30);

        // Seller A's ads home: o4 counts once although it touched two campaigns.
        $this->getJson('/api/seller/ads/overview')->assertOk()
            ->assertJsonPath('data.totals.clicks', 4)
            ->assertJsonPath('data.totals.impressions', 1)
            ->assertJsonPath('data.totals.spend', 1.6)
            ->assertJsonPath('data.totals.orders', 3)
            ->assertJsonPath('data.totals.revenue', 230)
            ->assertJsonPath('data.totals.roas', 143.75)
            ->assertJsonPath('data.totals.cost_per_order', 0.533)
            ->assertJsonCount(30, 'data.daily');

        $list = collect($this->getJson('/api/seller/ads/campaigns')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([2, 3], [$list[$c1->id]['stats']['clicks'], $list[$c1->id]['stats']['orders']]);
        $this->assertSame([2, 1], [$list[$c2->id]['stats']['clicks'], $list[$c2->id]['stats']['orders']]);
        $this->assertArrayNotHasKey($c3->id, $list->all(), 'another seller\'s campaign never shows');
        $this->getJson("/api/seller/ads/campaigns/{$c3->id}")->assertNotFound();

        // Seller B sees only their own campaign.
        Sanctum::actingAs($b);
        $this->getJson('/api/seller/ads/overview')->assertOk()
            ->assertJsonPath('data.totals.clicks', 1)
            ->assertJsonPath('data.totals.spend', 0.4)
            ->assertJsonPath('data.totals.orders', 0)
            ->assertJsonPath('data.totals.roas', 0);

        // Spend = what left the wallets.
        $this->assertEqualsWithDelta(1.6, -DB::table('ad_wallet_transactions')->where('seller_id', $a->id)->where('type', 'click_charge')->sum('amount'), 0.0005);

        // o1 is delivered, then refunded: it stops counting.
        Order::where('id', $o1->id)->update(['status' => 'delivered']);
        $this->assertSame(3, app(AdMetrics::class)->summary([$c1->id])['orders']);
        Order::where('id', $o1->id)->update(['status' => 'refunded']);
        $this->assertSame(2, app(AdMetrics::class)->summary([$c1->id])['orders']);

        // The admin sees the same numbers, and the nightly rebuild changes nothing.
        Sanctum::actingAs($this->makeUser('admin'));
        $this->getJson("/api/admin/ads/campaigns/{$c1->id}")->assertOk()
            ->assertJsonPath('data.summary.clicks', 2)->assertJsonPath('data.summary.orders', 2)->assertJsonPath('data.summary.revenue', 100);
        $before = app(AdMetrics::class)->perCampaign([$c1->id, $c2->id, $c3->id]);
        $this->artisan('ads:rebuild-stats', ['--all' => true])->assertExitCode(0);
        $this->artisan('ads:rebuild-stats', ['--all' => true])->assertExitCode(0);
        $this->assertSame($before, app(AdMetrics::class)->perCampaign([$c1->id, $c2->id, $c3->id]));
        $this->assertSame(2, (int) Sponsorship::find($c1->id)->clicks);
        $this->assertSame(2, (int) DB::table('sponsorship_daily_stats')->where('sponsorship_id', $c1->id)->sum('clicks'));
    }

    public function test_legacy_campaign_repeat_clicks_do_not_count(): void
    {
        $cat = $this->makeCategory();
        $seller = $this->seller();
        $c = $this->campaign($seller, $this->readyProduct($seller, $cat));
        $c->forceFill(['pricing_model' => Sponsorship::PRICING_LEGACY])->save();
        $buyer = $this->makeUser();

        $this->assertSame('not_cpc', $this->click($c, $buyer)['reason']);
        $this->assertSame('duplicate', $this->click($c, $buyer)['reason']);
        $this->assertSame(1, app(AdMetrics::class)->summary([$c->id])['clicks']);
        $this->assertSame(0.0, app(AdMetrics::class)->summary([$c->id])['spend']);
    }

    public function test_africa_tunis_midnight_splits_days_and_resets_today_budget(): void
    {
        $cat = $this->makeCategory();
        $seller = $this->seller();
        $c = $this->campaign($seller, $this->readyProduct($seller, $cat));
        [$x, $y] = [$this->makeUser(), $this->makeUser()];

        Carbon::setTestNow('2026-09-30 22:59:00');   // 23:59 in Tunis, 30 Sept
        $this->assertTrue($this->click($c, $x)['billable']);
        Carbon::setTestNow('2026-09-30 23:01:00');   // 00:01 in Tunis, 1 Oct
        $this->assertTrue($this->click($c, $y)['billable']);
        $this->order($y, [[$c->product, 1]]);

        $c->refresh();
        $this->assertSame('2026-10-01', $c->spent_today_date->toDateString());
        $this->assertEqualsWithDelta(0.4, (float) $c->spent_today, 0.0005, 'a new ad day starts at midnight Tunis');

        Sanctum::actingAs($seller);
        $res = $this->getJson("/api/seller/ads/campaigns/{$c->id}")->assertOk()
            ->assertJsonPath('data.spent_today', 0.4);
        $days = collect($res->json('data.daily'))->keyBy('date');
        $this->assertSame('2026-10-01', $res->json('data.daily.29.date'));
        $this->assertSame([1, 0.4, 0], [$days['2026-09-30']['clicks'], $days['2026-09-30']['cost'], $days['2026-09-30']['orders']]);
        $this->assertSame([1, 0.4, 1], [$days['2026-10-01']['clicks'], $days['2026-10-01']['cost'], $days['2026-10-01']['orders']]);

        $this->assertSame(['2026-09-30', '2026-10-01'], DB::table('ad_wallet_transactions')->where('sponsorship_id', $c->id)
            ->orderBy('rollup_date')->pluck('rollup_date')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        $this->assertEqualsWithDelta(0.4, app(AdMetrics::class)->platformRevenue('2026-10-01', '2026-10-01')['paid'] - 0, 0.0005);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function campaign(User $seller, Product $product): Sponsorship
    {
        $this->fund($seller, 100);
        return app(SponsorshipService::class)->create($seller, $this->campaignData($product, ['daily_budget' => 5, 'max_cpc' => 0.5]));
    }

    /** A signed ad token as the ad server issues it (auction price 0.400). */
    private function token(Sponsorship $c, string $placement = 'home_row'): string
    {
        return app(AdTokenService::class)->issue([
            'c' => $c->id, 'p' => $c->product_id, 'pl' => $placement, 'u' => null, 's' => null,
            'r' => (string) Str::uuid(), 'cpc' => 0.4,
        ]);
    }

    private function viewer(User $u): array
    {
        return ['user_id' => $u->id, 'session_id' => null, 'ip' => '10.1.' . intdiv($u->id, 250) % 250 . '.' . ($u->id % 250), 'user_agent' => 'Mozilla/5.0'];
    }

    private function click(Sponsorship $c, User $u): array
    {
        return $this->events->record($this->token($c), 'click', $this->viewer($u));
    }

    /** An order placed now, one seller order per seller, credited to ads like checkout does. */
    private function order(User $buyer, array $lines): Order
    {
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $buyer->id, 'order_number' => 'ACC-' . Str::random(10), 'total_amount' => 0,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sellerOrders = [];
        foreach ($lines as [$product, $qty]) {
            $sellerOrders[$product->seller_id] ??= DB::table('seller_orders')->insertGetId([
                'order_id' => $orderId, 'seller_id' => $product->seller_id, 'status' => 'pending',
                'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $total = (float) $product->price * $qty;
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'seller_order_id' => $sellerOrders[$product->seller_id], 'product_id' => $product->id,
                'quantity' => $qty, 'price' => $product->price, 'total' => $total, 'net_total' => $total,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $order = Order::find($orderId);
        app(AttributionService::class)->recordOrder($order);
        return $order;
    }
}
