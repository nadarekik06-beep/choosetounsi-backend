<?php

namespace Tests\Feature\Ads;

use App\Jobs\GenerateAdCopy;
use App\Models\AdWallet;
use App\Notifications\Ads\CampaignEnded;
use App\Notifications\Ads\CampaignPaused;
use App\Services\Ads\AdSettings;
use App\Services\Ads\AdWalletService;
use App\Services\Ads\SponsorshipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin sponsoring API: overview & revenue, moderation, settings, wallets, finance ad revenue.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/AdminAdsTest.php
 */
class AdminAdsTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        Notification::fake();
        Bus::fake([GenerateAdCopy::class]);
    }

    private function campaignWithSpend()
    {
        $seller = $this->seller();
        $this->fund($seller, 20);
        app(AdWalletService::class)->grantMonthlyCredit($seller->id, 0.3, now()->addDays(10), 'admin-test');
        $c = app(SponsorshipService::class)->create($seller, $this->campaignData($this->readyProduct($seller)));
        app(AdWalletService::class)->charge($c, 0.3);   // from credit
        app(AdWalletService::class)->charge($c, 0.3);   // paid
        return $c;
    }

    public function test_admin_only(): void
    {
        Sanctum::actingAs($this->seller());
        $this->getJson('/api/admin/ads/overview')->assertForbidden();
    }

    public function test_overview_separates_paid_revenue_from_credit_and_finance_shows_it(): void
    {
        $c = $this->campaignWithSpend();
        Sanctum::actingAs($this->makeUser('admin'));

        $res = $this->getJson('/api/admin/ads/overview?days=7')->assertOk();
        $this->assertGreaterThanOrEqual(0.3, $res->json('data.revenue.paid'));
        $this->assertGreaterThanOrEqual(0.3, $res->json('data.revenue.credit'));
        $this->assertContains($c->seller_id, array_column($res->json('data.top_advertisers'), 'seller_id'));
        $this->assertArrayHasKey('suspicious_ips', $res->json('data.flags'));

        $kpis = $this->getJson('/api/admin/finance/overview?period=all')->assertOk()->json('data.kpis');
        $this->assertGreaterThanOrEqual(0.3, $kpis['ad_revenue']);
        $this->assertArrayHasKey('ad_credit_spent', $kpis);
    }

    public function test_moderation_reject_refunds_and_pause_is_admin_only(): void
    {
        $c = $this->campaignWithSpend();
        $admin = $this->makeUser('admin');
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/ads/campaigns?search={$c->seller->email}")->assertOk()->assertJsonPath('data.0.id', $c->id);
        $this->postJson("/api/admin/ads/campaigns/{$c->id}/pause")->assertOk()->assertJsonPath('data.paused_reason', 'admin');
        Notification::assertSentTo($c->seller, CampaignPaused::class);

        $this->postJson("/api/admin/ads/campaigns/{$c->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/ads/campaigns/{$c->id}/reject", ['reason' => 'Counterfeit'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Counterfeit');
        Notification::assertSentTo($c->seller, CampaignEnded::class);

        $wallet = AdWallet::where('seller_id', $c->seller_id)->first();
        $this->assertEquals(20.0, (float) $wallet->balance, 'paid part refunded');
        $this->assertEquals(0.3, (float) $wallet->credit_balance, 'credit part back to credit');
    }

    public function test_settings_are_validated_and_applied(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));

        $this->getJson('/api/admin/ads/settings')->assertOk()->assertJsonPath('data.values.min_cpc', 0.2);
        $this->putJson('/api/admin/ads/settings', ['min_cpc' => 0.25, 'popup_enabled' => false, 'digest_time' => '19:30',
            'tier_click_discount' => ['free' => 0, 'red' => 0.1, 'black' => 0.25]])->assertOk()
            ->assertJsonPath('data.values.min_cpc', 0.25)->assertJsonPath('data.values.popup_enabled', false);
        $this->assertSame(0.25, app(AdSettings::class)->float('min_cpc'));

        $this->putJson('/api/admin/ads/settings', ['nope' => 1])->assertStatus(422);
        $this->putJson('/api/admin/ads/settings', ['min_cpc' => -1])->assertStatus(422);
        $this->putJson('/api/admin/ads/settings', ['digest_time' => '25:00'])->assertStatus(422);
        $this->putJson('/api/admin/ads/settings', ['tier_click_discount' => ['red' => 3]])->assertStatus(422);
    }

    public function test_wallets_list_and_detail(): void
    {
        $c = $this->campaignWithSpend();
        Sanctum::actingAs($this->makeUser('admin'));

        $this->getJson("/api/admin/ads/wallets?search={$c->seller->email}")->assertOk()->assertJsonPath('data.0.seller.id', $c->seller_id);
        $this->getJson("/api/admin/ads/wallets/{$c->seller_id}")->assertOk()
            ->assertJsonStructure(['data' => ['seller', 'wallet', 'transactions', 'top_ups']]);
    }
}
