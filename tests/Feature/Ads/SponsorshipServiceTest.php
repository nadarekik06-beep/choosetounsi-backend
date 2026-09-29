<?php

namespace Tests\Feature\Ads;

use App\Exceptions\Ads\AdRuleViolation;
use App\Jobs\GenerateAdCopy;
use App\Models\AdWallet;
use App\Models\Sponsorship;
use App\Notifications\Ads\CampaignActivated;
use App\Notifications\Ads\CampaignEnded;
use App\Notifications\Ads\CampaignPaused;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdWalletService;
use App\Services\Ads\SponsorshipService;
use App\Services\PlanDowngradeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Campaign lifecycle and rules: readiness gate, floors, wallet ≥ one day of
 * budget, one open campaign per product (service + DB), plan limits,
 * pause/resume/cancel/complete/reject, plan-downgrade pause & resume.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/SponsorshipServiceTest.php
 */
class SponsorshipServiceTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    private SponsorshipService $campaigns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        Notification::fake();
        Bus::fake([GenerateAdCopy::class]);
        $this->campaigns = app(SponsorshipService::class);
    }

    private function violation(callable $fn): AdRuleViolation
    {
        try {
            $fn();
        } catch (AdRuleViolation $e) {
            return $e;
        }
        $this->fail('expected an AdRuleViolation');
    }

    public function test_create_starts_an_active_cpc_campaign(): void
    {
        $seller  = $this->seller('red');
        $product = $this->readyProduct($seller);
        $this->fund($seller, 20);

        $c = $this->campaigns->create($seller, $this->campaignData($product, [
            'end_date' => AdClock::now()->addDays(7)->toDateString(), 'target_wilaya_ids' => ['Kef', 'sfax'],
        ]));

        $this->assertSame('active', $c->status);
        $this->assertSame('cpc', $c->pricing_model);
        $this->assertSame('red', $c->plan_type);
        $this->assertEquals(5.0, (float) $c->daily_budget);
        $this->assertEquals(0.3, (float) $c->max_cpc);
        $this->assertSame(['Le Kef', 'Sfax'], $c->target_wilaya_ids);
        $this->assertNotEmpty($c->ai_ad_copy, 'template copy until the AI job runs');
        $this->assertSame(
            AdClock::now()->addDays(7)->toDateString(),
            $c->end_at->copy()->setTimezone(AdClock::timezone())->toDateString(),
            'end date is the last Africa/Tunis day'
        );
        $this->assertSame(0.0, (float) AdWallet::where('seller_id', $seller->id)->value('balance') - 20, 'nothing charged upfront');

        Bus::assertDispatched(GenerateAdCopy::class, fn ($job) => $job->sponsorshipId === $c->id);
        Notification::assertSentTo($seller, CampaignActivated::class);
    }

    public function test_product_that_is_not_ready_cannot_be_boosted(): void
    {
        $seller  = $this->seller();
        $product = $this->readyProduct($seller, null, [], images: 0);
        $this->fund($seller, 20);

        $e = $this->violation(fn () => $this->campaigns->create($seller, $this->campaignData($product)));
        $this->assertSame('NOT_READY', $e->errorCode);
        $this->assertContains('no_image', array_column($e->extra['readiness']['blockers'], 'code'));
    }

    public function test_bid_budget_and_wallet_floors(): void
    {
        $seller  = $this->seller();
        $product = $this->readyProduct($seller);
        $this->fund($seller, 4);

        $this->assertSame('CPC_BELOW_MIN', $this->violation(fn () => $this->campaigns->create($seller, $this->campaignData($product, ['max_cpc' => 0.1])))->errorCode);
        $this->assertSame('BUDGET_BELOW_MIN', $this->violation(fn () => $this->campaigns->create($seller, $this->campaignData($product, ['daily_budget' => 2])))->errorCode);

        $e = $this->violation(fn () => $this->campaigns->create($seller, $this->campaignData($product, ['daily_budget' => 5])));
        $this->assertSame('WALLET_TOO_LOW', $e->errorCode, 'wallet must hold one day of budget');
        $this->assertSame(402, $e->status);
    }

    public function test_one_open_campaign_per_product_in_service_and_database(): void
    {
        $seller  = $this->seller();
        $product = $this->readyProduct($seller);
        $this->fund($seller, 50);
        $first = $this->campaigns->create($seller, $this->campaignData($product));

        $this->assertSame('ALREADY_OPEN', $this->violation(fn () => $this->campaigns->create($seller, $this->campaignData($product)))->errorCode);

        // The DB guard holds even if the service is bypassed…
        try {
            DB::table('sponsorships')->insert(['seller_id' => $seller->id, 'product_id' => $product->id, 'status' => 'paused', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('a second open campaign must violate uq_sponsorships_open_product');
        } catch (QueryException $e) {
            $this->assertStringContainsString('uq_sponsorships_open_product', $e->getMessage());
        }

        // …and a finished campaign frees the product.
        $this->campaigns->cancel($first);
        $this->assertSame('active', $this->campaigns->create($seller, $this->campaignData($product))->status);
    }

    public function test_plan_limit_counts_open_campaigns(): void
    {
        $seller = $this->seller($this->planWithLimit(1));
        $this->fund($seller, 50);
        $this->campaigns->create($seller, $this->campaignData($this->readyProduct($seller)));

        try {
            $this->campaigns->create($seller, $this->campaignData($this->readyProduct($seller)));
            $this->fail('expected the plan limit');
        } catch (HttpResponseException $e) {
            $this->assertSame('SPONSOR_LIMIT_REACHED', $e->getResponse()->getData(true)['code']);
        }
    }

    public function test_pause_resume_cancel(): void
    {
        $seller = $this->seller();
        $this->fund($seller, 20);
        $c = $this->campaigns->create($seller, $this->campaignData($this->readyProduct($seller)));

        $c = $this->campaigns->pause($c);
        $this->assertSame(['paused', 'manual'], [$c->status, $c->paused_reason]);
        Notification::assertNotSentTo($seller, CampaignPaused::class);

        $this->assertSame('active', $this->campaigns->resume($c)->status);

        $c = $this->campaigns->cancel($c);
        $this->assertSame('cancelled', $c->status);
        $this->assertNotNull($c->ended_at);
        $this->assertSame('NOT_OPEN', $this->violation(fn () => $this->campaigns->cancel($c))->errorCode);
    }

    public function test_automatic_pauses_notify_except_the_daily_cap_and_admin_pauses_block_seller_resume(): void
    {
        $seller = $this->seller();
        $this->fund($seller, 20);
        $c = $this->campaigns->create($seller, $this->campaignData($this->readyProduct($seller)));

        $c = $this->campaigns->pause($c, Sponsorship::PAUSE_BUDGET_TODAY);
        Notification::assertNotSentTo($seller, CampaignPaused::class);
        $c->update(['spent_today' => 5, 'spent_today_date' => AdClock::today()]);
        $this->assertSame('BUDGET_EXHAUSTED_TODAY', $this->violation(fn () => $this->campaigns->resume($c))->errorCode);

        $c->update(['status' => 'active', 'paused_reason' => null, 'spent_today' => 0]);
        $c = $this->campaigns->pause($c->fresh(), Sponsorship::PAUSE_ADMIN);
        Notification::assertSentTo($seller, CampaignPaused::class);
        $this->assertSame('PAUSED_BY_ADMIN', $this->violation(fn () => $this->campaigns->resume($c))->errorCode);
        $this->assertSame('active', $this->campaigns->resume($c, byAdmin: true)->status);
    }

    public function test_reject_refunds_every_charge_and_notifies(): void
    {
        $seller = $this->seller();
        $this->fund($seller, 20);
        $c = $this->campaigns->create($seller, $this->campaignData($this->readyProduct($seller)));
        $wallets = app(AdWalletService::class);
        $wallets->charge($c, 0.3);
        $wallets->charge($c, 0.3);
        $this->assertEquals(19.4, $wallets->available($seller->id));

        $c = $this->campaigns->reject($c, 'Misleading photos', $this->makeUser('admin'));

        $this->assertSame('rejected', $c->status);
        $this->assertSame('Misleading photos', $c->rejection_reason);
        $this->assertEquals(20.0, $wallets->available($seller->id));
        Notification::assertSentTo($seller, CampaignEnded::class);
    }

    public function test_ended_campaigns_complete_on_schedule(): void
    {
        $seller = $this->seller();
        $this->fund($seller, 20);
        $c = $this->campaigns->create($seller, $this->campaignData($this->readyProduct($seller)));
        $c->update(['end_at' => now()->subMinute()]);

        $this->artisan('ads:complete-ended')->assertExitCode(0);

        $this->assertSame('completed', $c->fresh()->status);
        Notification::assertSentTo($seller, CampaignEnded::class);
    }

    public function test_plan_downgrade_pauses_and_upgrade_resumes(): void
    {
        $seller = $this->seller();
        $this->fund($seller, 20);
        $c = $this->campaigns->create($seller, $this->campaignData($this->readyProduct($seller)));

        app(PlanDowngradeService::class)->pauseSponsorships($seller->id, 0);
        $c->refresh();
        $this->assertSame(['paused', 'plan_downgrade'], [$c->status, $c->paused_reason]);
        $this->assertSame('PAUSED_BY_PLAN', $this->violation(fn () => $this->campaigns->resume($c))->errorCode);

        app(PlanDowngradeService::class)->resumeSponsorships($seller->id);
        $this->assertSame('active', $c->fresh()->status);
    }

    public function test_update_changes_budget_and_bid_within_the_rules(): void
    {
        $seller = $this->seller();
        $this->fund($seller, 20);
        $c = $this->campaigns->create($seller, $this->campaignData($this->readyProduct($seller)));

        $c = $this->campaigns->update($c, ['daily_budget' => 8, 'max_cpc' => 0.5, 'placements' => ['home_row', 'search_top']]);
        $this->assertEquals(8.0, (float) $c->daily_budget);
        $this->assertEquals(0.5, (float) $c->max_cpc);
        $this->assertSame(['home_row', 'search_top'], $c->placements);

        $this->assertSame('BUDGET_BELOW_CPC', $this->violation(fn () => $this->campaigns->update($c, ['daily_budget' => 3.2, 'max_cpc' => 4]))->errorCode);
    }
}
