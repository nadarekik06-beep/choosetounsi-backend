<?php

namespace Tests\Feature\Ads;

use App\Jobs\GenerateAdCopy;
use App\Models\AdWallet;
use App\Models\Order;
use App\Models\OrderAdAttribution;
use App\Models\Product;
use App\Models\Sponsorship;
use App\Models\SponsorshipEvent;
use App\Models\User;
use App\Models\UserInteraction;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdEventService;
use App\Services\Ads\AdRequest;
use App\Services\Ads\AdMetrics;
use App\Services\Ads\AdServer;
use App\Services\Ads\AdTokenService;
use App\Services\Ads\AttributionService;
use App\Services\Ads\SponsorshipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ad server and billing: relevance gate, ranking, second price, one ad per
 * seller, tokens, impression dedupe + frequency cap, click dedupe / bots / self
 * clicks, budget and wallet pauses, last-click attribution and its reversal.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/AdServingTest.php
 */
class AdServingTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    private AdServer $server;
    private AdEventService $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        Notification::fake();
        Bus::fake([GenerateAdCopy::class]);
        $this->server = app(AdServer::class);
        $this->events = app(AdEventService::class);
    }

    /** A running CPC campaign for a fresh product of a fresh seller in $category. */
    private function campaign(\App\Models\Category $category, array $overrides = [], string $plan = 'free', ?User $seller = null): Sponsorship
    {
        $seller ??= $this->seller($plan);
        $this->fund($seller, 100);
        $product = $this->readyProduct($seller, $category);
        $c = app(SponsorshipService::class)->create($seller, $this->campaignData($product, $overrides));
        AdServer::flushEligible();
        return $c;
    }

    /** A viewer who has been browsing $category. */
    private function interestedBuyer(\App\Models\Category $category): User
    {
        $buyer = $this->makeUser();
        $p = $this->makeProduct($this->makeUser('seller'), $category);
        foreach (range(1, 3) as $i) {
            UserInteraction::create(['user_id' => $buyer->id, 'product_id' => $p->id, 'seller_id' => $p->seller_id,
                'category_id' => $category->id, 'event_type' => 'view', 'created_at' => now()->subMinutes($i)]);
        }
        return $buyer;
    }

    private function viewer(User $u): array
    {
        return ['user_id' => $u->id, 'session_id' => null, 'ip' => '10.0.0.' . ($u->id % 250), 'user_agent' => 'Mozilla/5.0'];
    }

    private function serveFor(User $u, string $placement = 'home_row', array $extra = []): array
    {
        $req = new AdRequest($placement, $u->id, null, 8);
        foreach ($extra as $k => $v) {
            $req->{$k} = $v;
        }
        return $this->server->serve($req);
    }

    // ── Selection ───────────────────────────────────────────────────────────

    public function test_irrelevant_ads_are_not_shown_whatever_the_bid(): void
    {
        $shoes = $this->makeCategory();
        $tools = $this->makeCategory();
        $relevant = $this->campaign($shoes, ['max_cpc' => 0.2]);
        $richButIrrelevant = $this->campaign($tools, ['max_cpc' => 5, 'daily_budget' => 50]);

        $ads = $this->serveFor($this->interestedBuyer($shoes))['ads'];

        $ids = array_column(array_column($ads, 'sponsor_data'), 'id');
        $this->assertContains($relevant->id, $ids);
        $this->assertNotContains($richButIrrelevant->id, $ids);
        $this->assertTrue($ads[0]['is_sponsored']);
        $this->assertNotEmpty($ads[0]['ad_token']);
    }

    public function test_ranking_second_price_and_plan_discount(): void
    {
        $cat   = $this->makeCategory();
        $high  = $this->campaign($cat, ['max_cpc' => 1.0], 'black');
        $low   = $this->campaign($cat, ['max_cpc' => 0.4]);
        $buyer = $this->interestedBuyer($cat);

        $ranked = $this->server->auction(new AdRequest('home_row', $buyer->id, null, 8));
        $this->assertSame([$high->id, $low->id], array_column($ranked, 'id'));

        // Same relevance / pCTR / quality → the winner pays the runner-up's bid + 0.010, minus Black's 30 %.
        [$winner, $runnerUp] = $ranked;
        $this->assertEqualsWithDelta($winner['relevance'] * $winner['pctr'] * $winner['quality'],
            $runnerUp['relevance'] * $runnerUp['pctr'] * $runnerUp['quality'], 1e-9);
        $this->assertEqualsWithDelta(round((0.4 + 0.01) * 0.7, 3), $winner['charge'], 0.0005);
        $this->assertEqualsWithDelta(0.2, $runnerUp['charge'], 0.0005, 'no competitor below → the floor');
    }

    public function test_one_ad_per_seller_and_own_products_excluded(): void
    {
        $cat    = $this->makeCategory();
        $seller = $this->seller();
        $a = $this->campaign($cat, ['max_cpc' => 0.5], seller: $seller);
        $b = $this->campaign($cat, ['max_cpc' => 0.3], seller: $seller);

        $ids = array_column($this->server->auction(new AdRequest('home_row', $this->interestedBuyer($cat)->id)), 'id');
        $this->assertSame([$a->id], $ids);

        $this->assertSame([], $this->server->auction(new AdRequest('home_row', $seller->id)), 'a seller never sees their own ads');
    }

    public function test_context_placements_use_the_page(): void
    {
        $cat = $this->makeCategory();
        $other = $this->makeCategory();
        $c = $this->campaign($cat);
        $guest = new AdRequest('category_top', null, (string) Str::uuid(), 2);

        $guest->contextCategoryId = $cat->id;
        $this->assertSame([$c->id], array_column($this->server->auction($guest), 'id'));

        $guest->contextCategoryId = $other->id;
        $this->assertSame([], $this->server->auction($guest), 'another category page shows no ad for it');

        // On its own product page a product is never its own "similar" ad.
        $similar = new AdRequest('product_similar', null, (string) Str::uuid(), 4);
        $similar->contextProductId = $c->product_id;
        $this->assertNotContains($c->id, array_column($this->server->auction($similar), 'id'));
    }

    // ── Tokens & events ─────────────────────────────────────────────────────

    public function test_tampered_or_expired_tokens_are_rejected(): void
    {
        $tokens = app(AdTokenService::class);
        $token  = $tokens->issue(['c' => 1, 'p' => 1, 'pl' => 'home_row', 'r' => 'x', 'cpc' => 0.2]);
        $this->assertNotNull($tokens->verify($token));

        [$body, $sig] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['c' => 1, 'p' => 1, 'pl' => 'home_row', 'r' => 'x', 'cpc' => 9, 'x' => time() + 99])), '+/', '-_'), '=');
        $this->assertNull($tokens->verify("{$forged}.{$sig}"));
        $this->assertNull($tokens->verify($tokens->issue(['c' => 1, 'pl' => 'home_row', 'r' => 'x'], -1)));
        $this->assertSame('invalid_token', $this->events->record('garbage', 'click', ['user_id' => null, 'session_id' => null, 'ip' => null, 'user_agent' => null])['reason']);
    }

    public function test_impressions_are_deduplicated_and_frequency_capped(): void
    {
        $cat   = $this->makeCategory();
        $c     = $this->campaign($cat);
        $buyer = $this->interestedBuyer($cat);

        $token = $this->serveFor($buyer)['ads'][0]['ad_token'];
        $this->assertTrue($this->events->record($token, 'impression', $this->viewer($buyer))['accepted']);
        $this->assertSame('duplicate', $this->events->record($token, 'impression', $this->viewer($buyer))['reason']);
        $this->assertSame(1, $c->fresh()->impressions);

        // Cap: 3 impressions a day per viewer and campaign (other placements count too).
        Cache::put("ads:freq:u{$buyer->id}:{$c->id}:" . AdClock::today(), 3, 3600);
        $this->assertSame([], $this->serveFor($buyer)['ads']);
    }

    public function test_click_billing_dedupe_bots_and_self_clicks(): void
    {
        $cat   = $this->makeCategory();
        $c     = $this->campaign($cat, ['max_cpc' => 0.5]);
        $buyer = $this->interestedBuyer($cat);
        $ad    = $this->serveFor($buyer)['ads'][0];
        $price = app(AdServer::class)->auction(new AdRequest('home_row', $buyer->id))[0]['charge'];

        $first = $this->events->record($ad['ad_token'], 'click', $this->viewer($buyer));
        $this->assertTrue($first['billable']);
        $this->assertEqualsWithDelta($price, $first['cost'], 0.0005);
        $this->assertEqualsWithDelta(100 - $price, AdWallet::where('seller_id', $c->seller_id)->first()->available(), 0.0005);

        $again = $this->events->record($ad['ad_token'], 'click', $this->viewer($buyer));
        $this->assertFalse($again['billable']);
        $this->assertSame('duplicate', $again['reason']);

        $bot = $this->events->record($ad['ad_token'], 'click', ['user_id' => null, 'session_id' => null, 'ip' => '1.2.3.4', 'user_agent' => 'Googlebot/2.1']);
        $this->assertSame('bot', $bot['reason']);

        $self = $this->events->record($ad['ad_token'], 'click', ['user_id' => $c->seller_id, 'session_id' => null, 'ip' => '1.2.3.5', 'user_agent' => 'Mozilla']);
        $this->assertSame('self_click', $self['reason']);

        $c->refresh();
        $this->assertSame(1, $c->clicks, 'only the real click counts');
        $this->assertEqualsWithDelta($price, (float) $c->spent_today, 0.0005);
        $this->assertSame(4, SponsorshipEvent::where('sponsorship_id', $c->id)->where('event', 'click')->count(), 'every click is logged');
        $this->assertSame(1, (int) DB::table('sponsorship_daily_stats')->where('sponsorship_id', $c->id)->value('clicks'));
    }

    public function test_daily_budget_pauses_the_campaign_and_the_new_day_resumes_it(): void
    {
        $cat = $this->makeCategory();
        $c   = $this->campaign($cat, ['daily_budget' => 3, 'max_cpc' => 1.5]);
        $c->update(['spent_today' => 2.75, 'spent_today_date' => AdClock::today()]);   // 0.250 left today

        $buyer = $this->interestedBuyer($cat);
        $ad    = $this->serveFor($buyer)['ads'][0];
        $click = $this->events->record($ad['ad_token'], 'click', $this->viewer($buyer));
        $this->assertTrue($click['billable']);
        $this->assertEqualsWithDelta(0.2, $click['cost'], 0.0005);

        $c->refresh();
        $this->assertSame('paused', $c->status);
        $this->assertSame(Sponsorship::PAUSE_BUDGET_TODAY, $c->paused_reason);
        $this->assertEqualsWithDelta(2.95, (float) $c->spent_today, 0.0005, 'what is left (0.050) is below one click → paused');
        $this->assertSame([], $this->serveFor($this->interestedBuyer($cat))['ads'], 'not served once paused');

        $c->update(['spent_today_date' => AdClock::now()->subDay()->toDateString()]);   // it's tomorrow
        $this->artisan('ads:reset-daily')->assertExitCode(0);
        $this->assertSame('active', $c->fresh()->status);
    }

    public function test_empty_wallet_pauses_the_campaign(): void
    {
        $cat = $this->makeCategory();
        $c   = $this->campaign($cat, ['daily_budget' => 50, 'max_cpc' => 5]);
        $buyer = $this->interestedBuyer($cat);
        $ad = $this->serveFor($buyer)['ads'][0];

        // The wallet is drained between the page view and the click.
        app(\App\Services\Ads\AdWalletService::class)->adminAdjust($c->seller_id, -99.9, 0, 'drain', $this->makeUser('admin')->id);
        $res = $this->events->record($ad['ad_token'], 'click', $this->viewer($buyer));

        $this->assertFalse($res['billable']);
        $this->assertSame('wallet_empty', $res['reason']);
        $c->refresh();
        $this->assertSame('paused', $c->status);
        $this->assertSame(Sponsorship::PAUSE_WALLET_EMPTY, $c->paused_reason);
    }

    // ── Attribution ─────────────────────────────────────────────────────────

    private function order(User $buyer, Product $product, float $total = 50, ?\Carbon\Carbon $at = null): Order
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => $buyer->id, 'order_number' => 'ADS-' . Str::random(10), 'total_amount' => $total,
            'status' => 'pending', 'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'product_id' => $product->id, 'quantity' => 1, 'price' => $total, 'total' => $total,
            'net_total' => $total, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Order::find($id);
    }

    public function test_orders_are_credited_to_the_last_click_and_reversed_on_refund(): void
    {
        $cat   = $this->makeCategory();
        $c     = $this->campaign($cat);
        $buyer = $this->interestedBuyer($cat);
        $this->events->record($this->serveFor($buyer)['ads'][0]['ad_token'], 'click', $this->viewer($buyer));
        $metrics = app(AdMetrics::class);

        $order = $this->order($buyer, $c->product, 50);
        $this->assertSame(1, app(AttributionService::class)->recordOrder($order));
        $this->assertSame(1, $metrics->summary([$c->id])['orders'], 'counted once placed');
        $this->assertEquals(50.0, $metrics->summary([$c->id])['revenue']);

        // Status changes go through the query builder in the app (no model events): still seen.
        Order::where('id', $order->id)->update(['status' => 'delivered']);
        Order::where('id', $order->id)->update(['status' => 'completed']);
        $this->assertSame(1, $metrics->summary([$c->id])['orders'], 'a second "done" status never counts twice');

        Order::where('id', $order->id)->update(['status' => 'refunded']);
        $this->assertSame(0, $metrics->summary([$c->id])['orders']);
        $this->assertEquals(0.0, $metrics->summary([$c->id])['revenue']);

        // The nightly rebuild snapshots the same thing.
        $this->artisan('ads:rebuild-stats')->assertExitCode(0);
        $this->assertSame('reversed', OrderAdAttribution::where('order_id', $order->id)->value('status'));
        $this->assertSame(0, $c->fresh()->attributed_orders);
        $this->assertSame(1, $c->fresh()->clicks);
    }

    public function test_no_attribution_without_a_click_or_outside_the_window(): void
    {
        $cat   = $this->makeCategory();
        $c     = $this->campaign($cat);
        $buyer = $this->interestedBuyer($cat);

        $this->assertSame(0, app(AttributionService::class)->recordOrder($this->order($this->makeUser(), $c->product)), 'no click, no credit');

        $this->events->record($this->serveFor($buyer)['ads'][0]['ad_token'], 'click', $this->viewer($buyer));
        SponsorshipEvent::where('sponsorship_id', $c->id)->update(['created_at' => now()->subDays(8)]);
        $this->assertSame(0, app(AttributionService::class)->recordOrder($this->order($buyer, $c->product)), '8 days > 7-day window');
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    public function test_public_endpoints(): void
    {
        $cat = $this->makeCategory();
        $c   = $this->campaign($cat);
        $sid = (string) Str::uuid();

        $res = $this->withHeaders(['X-Session-Id' => $sid])
            ->getJson("/api/ads?placement=category_top&category_slug={$cat->slug}")->assertOk();
        $token = $res->json('ads.0.ad_token');
        $this->assertNotNull($token);
        $this->assertSame($c->id, $res->json('ads.0.sponsor_data.id'));

        $this->withHeaders(['X-Session-Id' => $sid])
            ->postJson('/api/ads/events', ['events' => [['token' => $token, 'event' => 'impression'], ['token' => 'bad', 'event' => 'click']]])
            ->assertStatus(202)->assertJsonPath('results.0.accepted', true)->assertJsonPath('results.1.reason', 'invalid_token');

        $this->getJson('/api/ads?placement=nowhere')->assertStatus(422);
        $this->getJson("/api/sponsored-products?category_slug={$cat->slug}")->assertOk()->assertJsonPath('data.0.sponsor_data.id', $c->id);
        $this->postJson("/api/sponsorships/{$c->id}/click")->assertStatus(202);
        $this->assertSame(0, $c->fresh()->clicks, 'the old unsigned endpoint no longer counts');
    }

    public function test_popup_is_capped_per_viewer(): void
    {
        config(['ads.defaults.popup_min_relevance' => 0.25]);
        app(\App\Services\Ads\AdSettings::class)->flush();
        $cat   = $this->makeCategory();
        $this->campaign($cat);
        $buyer = $this->interestedBuyer($cat);
        $auth  = ['Authorization' => 'Bearer ' . $buyer->createToken('t')->plainTextToken];

        $ad = $this->withHeaders($auth)->getJson('/api/ads/popup')->assertOk()->json('ad');
        $this->assertNotNull($ad);
        $this->withHeaders($auth)->postJson('/api/ads/events', ['events' => [['token' => $ad['ad_token'], 'event' => 'impression']]]);

        $this->withHeaders($auth)->getJson('/api/ads/popup')->assertOk()->assertJsonPath('ad', null);
    }
}
