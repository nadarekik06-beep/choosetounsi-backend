<?php

namespace Tests\Feature\Ads;

use App\Exceptions\Ads\AdRuleViolation;
use App\Exceptions\Ads\InsufficientAdFunds;
use App\Models\AdTopUp;
use App\Models\AdWallet;
use App\Models\AdWalletTransaction as Tx;
use App\Models\Sponsorship;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdWalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ad wallet: credit first then balance, never negative, daily click roll-up,
 * credit expiry, refunds, admin adjustments, idempotent top-up settlement.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/AdWalletTest.php
 */
class AdWalletTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    private AdWalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        $this->wallets = app(AdWalletService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function campaign($seller): Sponsorship
    {
        return Sponsorship::create([
            'seller_id' => $seller->id, 'product_id' => $this->readyProduct($seller)->id,
            'status' => 'active', 'pricing_model' => 'cpc', 'daily_budget' => 5, 'max_cpc' => 0.3,
        ]);
    }

    public function test_clicks_spend_credit_first_then_balance_and_roll_up_per_day(): void
    {
        $seller = $this->seller('red');
        $this->wallets->topUp($seller->id, 10);
        $this->wallets->grantMonthlyCredit($seller->id, 0.5, AdClock::endOfMonth(), 'test-month');
        $campaign = $this->campaign($seller);

        $first  = $this->wallets->charge($campaign, 0.3);
        $second = $this->wallets->charge($campaign, 0.3);

        $this->assertSame(['credit' => 0.3, 'paid' => 0.0], ['credit' => $first['credit'], 'paid' => $first['paid']]);
        $this->assertSame(['credit' => 0.2, 'paid' => 0.1], ['credit' => $second['credit'], 'paid' => $second['paid']]);

        $wallet = AdWallet::where('seller_id', $seller->id)->first();
        $this->assertEquals(0.0, (float) $wallet->credit_balance);
        $this->assertEquals(9.9, (float) $wallet->balance);

        $rows = Tx::where('sponsorship_id', $campaign->id)->where('type', Tx::TYPE_CLICK_CHARGE)->get();
        $this->assertCount(1, $rows, 'one roll-up row per campaign per day');
        $this->assertEquals(-0.6, (float) $rows[0]->amount);
        $this->assertEquals(-0.5, (float) $rows[0]->credit_amount);
        $this->assertEquals(-0.1, $rows[0]->paidAmount());
        $this->assertSame(2, $rows[0]->meta['clicks']);
        $this->assertSame(AdClock::today(), $rows[0]->rollup_date->toDateString());
    }

    public function test_charge_never_overdraws(): void
    {
        $seller = $this->seller();
        $this->wallets->topUp($seller->id, 10);
        $this->wallets->adminAdjust($seller->id, -9.8, 0, 'leave 0.2', $this->makeUser('admin')->id);
        $campaign = $this->campaign($seller);

        try {
            $this->wallets->charge($campaign, 0.3);
            $this->fail('expected InsufficientAdFunds');
        } catch (InsufficientAdFunds $e) {
            $this->assertSame(402, $e->status);
            $this->assertSame(0.3, $e->extra['required']);
        }

        $this->assertEquals(0.2, (float) AdWallet::where('seller_id', $seller->id)->value('balance'));
        $this->assertSame(0, Tx::where('sponsorship_id', $campaign->id)->count());
    }

    public function test_expired_credit_is_swept_and_not_spendable(): void
    {
        $seller = $this->seller();
        $this->wallets->topUp($seller->id, 10);
        $this->wallets->grantMonthlyCredit($seller->id, 5, now()->addHour(), 'test-month');

        Carbon::setTestNow(now()->addHours(2));
        $this->assertEquals(10.0, $this->wallets->available($seller->id));

        $charge = $this->wallets->charge($this->campaign($seller), 0.3);
        $this->assertSame(0.0, $charge['credit']);
        $this->assertSame(1, Tx::where('seller_id', $seller->id)->where('type', Tx::TYPE_CREDIT_EXPIRY)->count());
        $this->assertEquals(0.0, (float) AdWallet::where('seller_id', $seller->id)->value('credit_balance'));
    }

    public function test_monthly_credit_is_granted_once_per_period_and_replaces_leftovers(): void
    {
        $seller = $this->seller('red');

        $this->assertNotNull($this->wallets->grantMonthlyCredit($seller->id, 10, AdClock::endOfMonth(), '2026-09'));
        $this->assertNull($this->wallets->grantMonthlyCredit($seller->id, 10, AdClock::endOfMonth(), '2026-09'), 'same period twice');
        $this->assertEquals(10.0, $this->wallets->available($seller->id));

        $this->wallets->grantMonthlyCredit($seller->id, 10, AdClock::endOfMonth()->addMonth(), '2026-10');
        $wallet = AdWallet::where('seller_id', $seller->id)->first();
        $this->assertEquals(10.0, (float) $wallet->credit_balance, 'leftover expired, new credit granted — not 20');
        $this->assertSame(1, Tx::where('seller_id', $seller->id)->where('type', Tx::TYPE_CREDIT_EXPIRY)->count());
    }

    public function test_refund_returns_paid_part_to_balance_and_credit_part_to_credit(): void
    {
        $seller = $this->seller();
        $this->wallets->topUp($seller->id, 10);
        $this->wallets->grantMonthlyCredit($seller->id, 1, AdClock::endOfMonth(), 'test-month');
        $campaign = $this->campaign($seller);
        foreach (range(1, 5) as $_) {
            $this->wallets->charge($campaign, 0.3);   // 1.0 from credit, 0.5 from balance
        }

        $tx = $this->wallets->refundCampaignCharges($campaign, 'rejected');
        $this->assertEquals(1.5, (float) $tx->amount);
        $this->assertEquals(1.0, (float) $tx->credit_amount);

        $wallet = AdWallet::where('seller_id', $seller->id)->first();
        $this->assertEquals(10.0, (float) $wallet->balance);
        $this->assertEquals(1.0, (float) $wallet->credit_balance);
        $this->assertNull($this->wallets->refundCampaignCharges($campaign, 'rejected'), 'nothing left to refund');
    }

    public function test_admin_adjust_cannot_go_below_zero(): void
    {
        $seller = $this->seller();
        $this->wallets->topUp($seller->id, 10);
        $admin = $this->makeUser('admin');

        $this->expectException(AdRuleViolation::class);
        $this->wallets->adminAdjust($seller->id, -10.5, 0, 'too much', $admin->id);
    }

    public function test_top_up_settles_exactly_once(): void
    {
        $seller = $this->seller();
        $topUp  = AdTopUp::create(['seller_id' => $seller->id, 'amount' => 25, 'gateway' => 'manual', 'status' => 'pending', 'reference' => 'D17-' . uniqid()]);

        $this->wallets->settleTopUp($topUp, true);
        $again = $this->wallets->settleTopUp($topUp, true);
        $late  = $this->wallets->settleTopUp($topUp, false);   // a late "failed" callback

        $this->assertSame('paid', $late->status);
        $this->assertSame($again->wallet_transaction_id, $late->wallet_transaction_id);
        $this->assertSame(1, Tx::where('seller_id', $seller->id)->where('type', Tx::TYPE_TOP_UP)->count());
        $this->assertEquals(25.0, $this->wallets->available($seller->id));
    }
}
