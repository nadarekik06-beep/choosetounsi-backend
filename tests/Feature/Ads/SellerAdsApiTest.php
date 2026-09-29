<?php

namespace Tests\Feature\Ads;

use App\Jobs\GenerateAdCopy;
use App\Models\AdTopUp;
use App\Models\AdWallet;
use App\Models\AdWalletTransaction as Tx;
use App\Models\Sponsorship;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seller ads API end to end: wallet + top-ups (sandbox, manual → admin),
 * campaigns, wizard tools, admin wallet endpoints, monthly credit command.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/SellerAdsApiTest.php
 */
class SellerAdsApiTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        Notification::fake();
        Bus::fake([GenerateAdCopy::class]);
        config(['ads.gateways.sandbox' => true, 'ads.gateways.manual' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ads_routes_need_a_seller_whose_plan_allows_sponsoring(): void
    {
        $this->getJson('/api/seller/ads/wallet')->assertUnauthorized();

        Sanctum::actingAs($this->makeUser('client'));
        $this->getJson('/api/seller/ads/wallet')->assertForbidden()->assertJsonPath('code', 'NOT_SELLER');
    }

    public function test_sandbox_top_up_credits_the_wallet_immediately(): void
    {
        Sanctum::actingAs($seller = $this->seller());

        $this->postJson('/api/seller/ads/wallet/top-up', ['amount' => 5, 'gateway' => 'sandbox'])
            ->assertStatus(422)->assertJsonPath('code', 'TOP_UP_BELOW_MIN');

        $this->postJson('/api/seller/ads/wallet/top-up', ['amount' => 50, 'gateway' => 'sandbox'])
            ->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.wallet.balance', 50);

        $this->getJson('/api/seller/ads/wallet')->assertOk()
            ->assertJsonPath('data.available', 50)->assertJsonPath('data.min_top_up', 10);
        $this->getJson('/api/seller/ads/wallet/transactions')->assertOk()->assertJsonPath('data.0.type', 'top_up');

        config(['ads.gateways.sandbox' => false]);
        $this->postJson('/api/seller/ads/wallet/top-up', ['amount' => 50, 'gateway' => 'sandbox'])->assertStatus(422);
    }

    public function test_manual_top_up_waits_for_an_admin_and_settles_once(): void
    {
        Sanctum::actingAs($seller = $this->seller());
        $ref = 'D17-' . Str::random(8);

        $res = $this->postJson('/api/seller/ads/wallet/top-up', ['amount' => 30, 'gateway' => 'manual', 'reference' => $ref])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.wallet.available', 0);
        $id = $res->json('data.top_up.id');

        // The same transfer reference can't be submitted twice.
        $this->postJson('/api/seller/ads/wallet/top-up', ['amount' => 30, 'gateway' => 'manual', 'reference' => $ref])->assertStatus(409);

        Sanctum::actingAs($this->makeUser('admin'));
        $this->postJson("/api/admin/ads/top-ups/{$id}/confirm")->assertOk()
            ->assertJsonPath('data.top_up.status', 'paid')->assertJsonPath('data.already_settled', false);
        $this->postJson("/api/admin/ads/top-ups/{$id}/confirm")->assertOk()->assertJsonPath('data.already_settled', true);
        $this->postJson("/api/admin/ads/top-ups/{$id}/reject")->assertOk()->assertJsonPath('data.top_up.status', 'paid');

        $this->assertSame(1, Tx::where('seller_id', $seller->id)->where('type', 'top_up')->count());
        $this->assertEquals(30.0, (float) AdWallet::where('seller_id', $seller->id)->value('balance'));
        $this->assertSame('paid', AdTopUp::find($id)->status);
    }

    public function test_campaign_lifecycle_over_the_api(): void
    {
        Sanctum::actingAs($seller = $this->seller());
        $product = $this->readyProduct($seller);

        $this->postJson('/api/seller/ads/campaigns', $this->campaignData($product))
            ->assertStatus(402)->assertJsonPath('code', 'WALLET_TOO_LOW');

        $this->fund($seller, 20);
        $id = $this->postJson('/api/seller/ads/campaigns', $this->campaignData($product, ['placements' => ['home_row']]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.placements', ['home_row'])
            ->assertJsonPath('data.can.pause', true)
            ->json('data.id');

        $this->postJson('/api/seller/ads/campaigns', $this->campaignData($product))->assertStatus(422)->assertJsonPath('code', 'ALREADY_OPEN');

        $this->getJson("/api/seller/ads/campaigns/{$id}")->assertOk()
            ->assertJsonPath('data.summary.spend', 0)->assertJsonStructure(['data' => ['summary', 'daily', 'placements']]);
        $this->patchJson("/api/seller/ads/campaigns/{$id}", ['daily_budget' => 7])->assertOk()->assertJsonPath('data.daily_budget', 7);
        $this->postJson("/api/seller/ads/campaigns/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/seller/ads/campaigns/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/seller/ads/campaigns/{$id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.refunded', 0);

        $this->getJson('/api/seller/ads/campaigns')->assertOk()->assertJsonPath('meta.total', 1);

        // Someone else's campaign is invisible.
        Sanctum::actingAs($this->seller());
        $this->getJson("/api/seller/ads/campaigns/{$id}")->assertNotFound();
    }

    public function test_wizard_tools(): void
    {
        Sanctum::actingAs($seller = $this->seller('black'));
        $product = $this->readyProduct($seller);

        $this->getJson("/api/seller/ads/config?product_id={$product->id}")->assertOk()
            ->assertJsonPath('data.tier', 'black')
            ->assertJsonPath('data.tier_click_discount', 0.3)
            ->assertJsonPath('data.min_cpc', 0.2)
            ->assertJsonPath('data.suggested_cpc', 0.3);

        $this->postJson('/api/seller/ads/readiness', ['product_id' => $product->id])->assertOk()->assertJsonPath('data.passes', true);
        $this->postJson('/api/seller/ads/forecast', ['product_id' => $product->id, 'daily_budget' => 5, 'days' => 7])->assertOk()
            ->assertJsonStructure(['data' => ['daily' => ['impressions', 'clicks', 'orders', 'spend'], 'total', 'assumptions']]);

        $other = $this->readyProduct($this->seller());
        $this->postJson('/api/seller/ads/readiness', ['product_id' => $other->id])->assertNotFound();

        $this->getJson('/api/ads/config')->assertOk()->assertJsonPath('data.popup.delay_seconds', 8);
    }

    public function test_legacy_sponsor_endpoint_respects_open_campaigns(): void
    {
        Sanctum::actingAs($seller = $this->seller());
        $product = $this->readyProduct($seller);
        $this->fund($seller, 20);
        $this->postJson('/api/seller/ads/campaigns', $this->campaignData($product))->assertCreated();

        $this->postJson('/api/seller/sponsorships/sponsor', ['product_id' => $product->id, 'payment_token' => 'tok'])
            ->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_ACTIVE');

        $fresh = $this->readyProduct($seller);
        $this->postJson('/api/seller/sponsorships/sponsor', ['product_id' => $fresh->id, 'payment_token' => 'tok'])->assertCreated();
        $legacy = Sponsorship::where('product_id', $fresh->id)->first();
        $this->assertSame('legacy_daily', $legacy->pricing_model);
        $this->assertTrue((bool) $fresh->fresh()->is_sponsored);
    }

    public function test_admin_adjusts_a_wallet(): void
    {
        $seller = $this->seller();
        Sanctum::actingAs($this->makeUser('admin'));

        $this->postJson("/api/admin/ads/wallets/{$seller->id}/adjust", ['credit_delta' => 12, 'note' => 'Launch gift'])->assertOk()
            ->assertJsonPath('data.wallet.credit_balance', 12)
            ->assertJsonPath('data.transaction.note', 'Launch gift');
        $this->postJson("/api/admin/ads/wallets/{$seller->id}/adjust", ['balance_delta' => -1, 'note' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'ADJUST_BELOW_ZERO');
    }

    public function test_monthly_credit_command_grants_by_tier_once(): void
    {
        $green = $this->seller('free');
        $red   = $this->seller('red');
        $black = $this->seller('black');

        foreach ([$green, $red, $black] as $s) {
            $this->artisan('ads:grant-monthly-credit', ['--seller' => $s->id])->assertExitCode(0);
            $this->artisan('ads:grant-monthly-credit', ['--seller' => $s->id])->assertExitCode(0);   // re-run: no double grant
        }

        $this->assertNull(AdWallet::where('seller_id', $green->id)->first());
        $this->assertEquals(10.0, (float) AdWallet::where('seller_id', $red->id)->value('credit_balance'));
        $this->assertEquals(40.0, (float) AdWallet::where('seller_id', $black->id)->value('credit_balance'));

        // Credit expires at the end of the Africa/Tunis month.
        $expires = AdWallet::where('seller_id', $red->id)->value('credit_expires_at');
        $this->assertSame(
            now('Africa/Tunis')->endOfMonth()->format('Y-m-d H:i'),
            Carbon::parse($expires)->setTimezone('Africa/Tunis')->format('Y-m-d H:i')
        );
    }
}
