<?php

namespace Tests\Feature\Ads;

use App\Jobs\GenerateAdCopy;
use App\Models\Sponsorship;
use App\Notifications\Ads\CampaignLowPerformance;
use App\Services\Ads\AdOptimizer;
use App\Services\Ads\AdServer;
use App\Services\Ads\SponsorshipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Daily optimizer (tips, placement weights, weekly alerts) and the ads-home overview.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/AdOptimizerTest.php
 */
class AdOptimizerTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        Notification::fake();
        Bus::fake([GenerateAdCopy::class]);
    }

    private function campaign(): Sponsorship
    {
        $seller = $this->seller();
        $this->fund($seller, 100);
        $c = app(SponsorshipService::class)->create($seller, $this->campaignData($this->readyProduct($seller)));
        $c->update(['start_at' => now()->subDays(5)]);
        return $c->fresh();
    }

    public function test_placement_without_orders_is_down_weighted_and_low_roas_alerts_once(): void
    {
        $c = $this->campaign();
        $this->rawActivity($c, 'search_top', ['impressions' => 900, 'clicks' => 40, 'cost' => 12]);
        $this->rawActivity($c, 'home_row', ['impressions' => 600, 'clicks' => 20, 'cost' => 6, 'orders' => 1, 'revenue' => 10]);
        $this->artisan('ads:rebuild-stats')->assertExitCode(0);   // the ad server reads the roll-up

        $result = app(AdOptimizer::class)->optimize($c);

        $codes = array_column($result['tips'], 'code');
        $this->assertContains('low_roas', $codes);
        $this->assertContains('placement_no_orders', $codes);
        $this->assertSame(['search_top' => AdOptimizer::LOW_WEIGHT], (array) $result['placement_weights']);
        Notification::assertSentToTimes($c->seller, CampaignLowPerformance::class, 1);

        app(AdOptimizer::class)->optimize($c->fresh());
        Notification::assertSentToTimes($c->seller, CampaignLowPerformance::class, 1);   // weekly at most

        // The ad server applies the weight.
        AdServer::flushEligible();
        $this->assertSame(['search_top' => 0.5], app(AdServer::class)->eligible()['campaigns'][
            array_search($c->id, array_column(app(AdServer::class)->eligible()['campaigns'], 'id'))
        ]->weights);
    }

    public function test_no_reach_tip_after_a_few_days(): void
    {
        $c = $this->campaign();
        $this->artisan('ads:optimize')->assertExitCode(0);
        $this->assertContains('no_reach', array_column($c->fresh()->optimizer['tips'], 'code'));
    }

    public function test_overview_endpoint(): void
    {
        $c = $this->campaign();
        $this->rawActivity($c, 'home_row', ['impressions' => 100, 'clicks' => 5, 'cost' => 1.5, 'orders' => 1, 'revenue' => 45]);
        $this->rawActivity($c, 'home_row', ['impressions' => 50, 'clicks' => 1, 'cost' => 0.3], now()->subDays(40));

        Sanctum::actingAs($c->seller);
        $this->getJson('/api/seller/ads/overview?days=30')->assertOk()
            ->assertJsonPath('data.totals.spend', 1.5)
            ->assertJsonPath('data.totals.clicks', 5)
            ->assertJsonPath('data.totals.roas', 30)
            ->assertJsonPath('data.open_campaigns', 1)
            ->assertJsonCount(30, 'data.daily')           // every day, zeros included
            ->assertJsonPath('data.daily.29.clicks', 5);
        $this->getJson("/api/seller/ads/campaigns/{$c->id}")->assertOk()->assertJsonStructure(['data' => ['tips']]);
    }
}
