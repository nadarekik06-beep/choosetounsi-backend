<?php

namespace Tests\Feature\Payments;

use App\Jobs\GenerateAdCopy;
use App\Models\AdTopUp;
use App\Models\AdWallet;
use App\Models\AdWalletTransaction;
use App\Models\PaymentRequest;
use App\Models\PlatformSetting;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\Sponsorship;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Notifications\PaymentRequestDecided;
use App\Services\Ads\AdPricing;
use App\Services\Ads\AdWalletService;
use App\Services\Ads\SponsorshipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Ads\MakesAds;
use Tests\TestCase;

/**
 * Manual WhatsApp payments: seller requests (top-up / plan upgrade), the WhatsApp
 * link and message, admin approve (once) / reject, direct admin actions, access rules.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Payments/PaymentRequestTest.php
 */
class PaymentRequestTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        Notification::fake();
        Bus::fake([GenerateAdCopy::class]);
        // Known settings whatever the test database holds.
        PlatformSetting::where('key', 'like', 'payments.%')->delete();
        app(\App\Services\Ads\AdSettings::class)->set(['min_top_up' => 10]);
    }

    /** An approved seller whose store name and phone need URL-encoding. */
    private function payingSeller(string $plan = 'red'): User
    {
        $seller = $this->seller($plan);
        SellerApplication::where('user_id', $seller->id)->update([
            'business_name' => "Café l'Étoile & Fils", 'full_name' => 'Hédi Ben Salah', 'phone_number' => '+216 22 333 444',
        ]);
        return $seller;
    }

    private function textOf(string $url): string
    {
        $this->assertStringStartsWith('https://wa.me/21657252576?text=', $url);
        return rawurldecode(substr($url, strlen('https://wa.me/21657252576?text=')));
    }

    // ── Seller: top-up request ──────────────────────────────────────────────

    public function test_top_up_request_is_pending_with_reference_and_whatsapp_message(): void
    {
        $seller = $this->payingSeller();
        $this->fund($seller, 12.5);
        Sanctum::actingAs($seller);

        $res = $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 50])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.type', 'wallet_topup')
            ->assertJsonPath('data.amount', 50);
        $ref = $res->json('data.reference');
        $this->assertMatchesRegularExpression('/^CT-[A-HJ-NP-Z2-9]{5}$/', $ref);

        $row = PaymentRequest::where('reference', $ref)->firstOrFail();
        $this->assertSame($seller->id, $row->seller_id);
        $this->assertSame("Café l'Étoile & Fils", $row->store_name);
        $this->assertSame('12.500', $row->wallet_balance);
        $this->assertSame(['created'], $row->logs->pluck('action')->all());

        $url  = $res->json('data.whatsapp_url');
        $text = $this->textOf($url);
        $this->assertSame($row->message, $text);
        foreach ([
            'Bonjour, je souhaite recharger mon portefeuille publicitaire.',
            "Référence : {$ref}", 'Type : Recharge du portefeuille publicitaire', 'Montant : 50 DT',
            "Boutique : Café l'Étoile & Fils | Vendeur : Hédi Ben Salah | ID : {$seller->id}",
            "Email : {$seller->email} | Tél : +216 22 333 444", 'Solde actuel : 12,500 DT', 'Date : ',
        ] as $line) {
            $this->assertStringContainsString($line, $text);
        }

        // Accents, &, ', +, spaces and new lines are percent-encoded (UTF-8), never left raw.
        $query = substr($url, strpos($url, '?text=') + 6);
        $this->assertStringContainsString('Caf%C3%A9%20l%27%C3%89toile%20%26%20Fils', $query);
        $this->assertStringContainsString('%0A', $query);
        $this->assertStringContainsString('%2B216', $query);
        $this->assertDoesNotMatchRegularExpression('/[ \n&+\'é]/u', $query);

        // Listed on the wallet page with the method's config.
        $this->getJson('/api/seller/payment-requests?type=wallet_topup')->assertOk()
            ->assertJsonPath('data.0.reference', $ref)->assertJsonPath('config.enabled', true)
            ->assertJsonPath('config.min_top_up', 10);
    }

    public function test_top_up_limits_and_on_off_switch(): void
    {
        $seller = $this->payingSeller();
        Sanctum::actingAs($seller);

        $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 5])->assertStatus(422)
            ->assertJsonPath('code', 'AMOUNT_OUT_OF_RANGE');
        $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 999999])->assertStatus(422)
            ->assertJsonPath('code', 'AMOUNT_OUT_OF_RANGE');

        PlatformSetting::setValue('payments.whatsapp_enabled', false);
        $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 50])->assertStatus(403)
            ->assertJsonPath('code', 'METHOD_DISABLED');
        $this->assertSame(0, PaymentRequest::where('seller_id', $seller->id)->count());
    }

    public function test_settings_drive_the_number_and_the_template(): void
    {
        $admin = $this->makeUser('admin');
        Sanctum::actingAs($admin);
        $this->putJson('/api/admin/payment-requests/settings', ['whatsapp_number' => 'abc'])->assertStatus(422);
        $this->putJson('/api/admin/payment-requests/settings', ['min_top_up' => 50, 'max_top_up' => 20])->assertStatus(422);
        $this->putJson('/api/admin/payment-requests/settings', [
            'whatsapp_number' => '+216 99 000 111', 'template_wallet_topup' => 'Recharge {reference} — {amount} DT pour {store}',
            'min_top_up' => 20, 'max_top_up' => 300,
        ])->assertOk()->assertJsonPath('data.values.whatsapp_number', '+216 99 000 111')->assertJsonPath('data.values.min_top_up', 20);

        Sanctum::actingAs($this->payingSeller());
        $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 15])->assertStatus(422);
        $url = $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 25])->assertCreated()->json('data.whatsapp_url');
        $this->assertStringStartsWith('https://wa.me/21699000111?text=Recharge%20CT-', $url);
        $this->assertStringEndsWith(rawurlencode("— 25 DT pour Café l'Étoile & Fils"), $url);
    }

    // ── Seller: plan upgrade request ────────────────────────────────────────

    public function test_plan_upgrade_request_and_card_payment_is_gone(): void
    {
        $seller = $this->payingSeller('free');
        Sanctum::actingAs($seller);

        $this->postJson('/api/seller/subscription/upgrade', [
            'plan' => 'red', 'card_number' => '4242424242424242', 'expiry_date' => '12/30', 'cvv' => '123', 'cardholder_name' => 'Test',
        ])->assertStatus(410)->assertJsonPath('code', 'USE_PAYMENT_REQUEST');
        $this->assertSame('free', SellerApplication::where('user_id', $seller->id)->value('plan'));

        $res = $this->postJson('/api/seller/payment-requests/plan-upgrade', ['plan' => 'red', 'billing_period' => 'monthly'])
            ->assertCreated()->assertJsonPath('data.type', 'plan_upgrade')->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_plan.slug', 'free')->assertJsonPath('data.requested_plan.slug', 'red')
            ->assertJsonPath('data.billing_period', 'monthly');
        $this->assertEquals(\App\Models\SubscriptionPlan::forSlug('red')->price_monthly, $res->json('data.amount'));

        $text = $this->textOf($res->json('data.whatsapp_url'));
        $price = \App\Services\Payments\Money::plain(\App\Models\SubscriptionPlan::forSlug('red')->price_monthly);
        foreach ([
            'Référence : ' . $res->json('data.reference'), 'Type : Changement de plan',
            'Plan actuel : Green Pepper → Plan demandé : Red Pepper', "Prix : {$price} DT (mensuel)",
            "Boutique : Café l'Étoile & Fils | Vendeur : Hédi Ben Salah | ID : {$seller->id}", "Email : {$seller->email}",
        ] as $line) {
            $this->assertStringContainsString($line, $text);
        }

        // One open plan request at a time; not to a lower / free plan.
        $this->postJson('/api/seller/payment-requests/plan-upgrade', ['plan' => 'black'])->assertStatus(409)
            ->assertJsonPath('code', 'UPGRADE_PENDING');
        $this->postJson('/api/seller/payment-requests/plan-upgrade', ['plan' => 'free'])->assertStatus(422)
            ->assertJsonPath('code', 'PLAN_UNAVAILABLE');
        $this->assertSame('free', SellerApplication::where('user_id', $seller->id)->value('plan'));
    }

    // ── Admin: approve / reject ─────────────────────────────────────────────

    public function test_approve_top_up_credits_real_money_once_and_resumes_wallet_paused_campaigns(): void
    {
        $seller = $this->payingSeller();
        $this->fund($seller, 20);
        $campaign = app(SponsorshipService::class)->create($seller, $this->campaignData($this->readyProduct($seller)));
        app(SponsorshipService::class)->pause($campaign, Sponsorship::PAUSE_WALLET_EMPTY);
        $admin = $this->makeUser('admin');
        app(AdWalletService::class)->adminAdjust($seller->id, -20, 0, 'drain', $admin->id);

        Sanctum::actingAs($seller);
        $id = $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 50])->assertCreated()->json('data.id');

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/payment-requests/pending-count')->assertOk()->assertJsonPath('data.count', PaymentRequest::where('status', 'pending')->count());
        $this->postJson("/api/admin/payment-requests/{$id}/approve", ['amount_received' => 45])->assertStatus(422);   // method required
        $res = $this->postJson("/api/admin/payment-requests/{$id}/approve", [
            'amount_received' => 45, 'payment_method' => 'd17', 'transaction_reference' => 'D17-778899', 'note' => 'reçu',
        ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.amount_received', 45)
            ->assertJsonPath('data.payment_method', 'd17')->assertJsonPath('data.decided_by.id', $admin->id);
        $this->assertSame(['created', 'approved'], array_column($res->json('data.logs'), 'action'));
        $this->assertSame($admin->id, $res->json('data.logs.1.actor.id'));

        // Real money on the paid balance (not free credit), one top-up, one ledger row.
        $wallet = AdWallet::where('seller_id', $seller->id)->first();
        $this->assertEquals(45, (float) $wallet->balance);
        $this->assertEquals(0, (float) $wallet->credit_balance);
        $topUp = AdTopUp::findOrFail($res->json('data.ad_top_up_id'));
        $this->assertSame([AdTopUp::STATUS_PAID, 'whatsapp', $res->json('data.reference')], [$topUp->status, $topUp->gateway, $topUp->reference]);
        $tx = AdWalletTransaction::where('seller_id', $seller->id)->where('type', 'top_up')->where('reference', "top_up:{$topUp->id}")->get();
        $this->assertCount(1, $tx);
        $this->assertEquals(45, $tx[0]->paidAmount());

        $this->assertSame(Sponsorship::STATUS_ACTIVE, $campaign->fresh()->status);
        Notification::assertSentTo($seller, PaymentRequestDecided::class, fn ($n, $ch) => in_array('mail', $ch) && $n->request->id === $id);

        // Second click / second admin: refused, nothing moves.
        $this->postJson("/api/admin/payment-requests/{$id}/approve", ['payment_method' => 'd17'])->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_DECIDED');
        $this->postJson("/api/admin/payment-requests/{$id}/reject", ['reason' => 'late'])->assertStatus(409);
        $this->assertEquals(45, (float) $wallet->fresh()->balance);
        $this->assertSame(1, AdTopUp::where('reference', $res->json('data.reference'))->count());

        // Counted as real ad money in Finance and the Sponsoring overview.
        $this->assertGreaterThanOrEqual(45, $this->getJson('/api/admin/finance/overview?period=today')->assertOk()->json('data.kpis.ad_top_ups_received'));
        $this->assertGreaterThanOrEqual(45, $this->getJson('/api/admin/ads/overview?days=7')->assertOk()->json('data.top_ups_received.amount'));
    }

    public function test_approve_upgrade_switches_plan_with_dates_payment_and_monthly_credit(): void
    {
        $seller = $this->payingSeller('red');
        app(AdWalletService::class)->ensureMonthlyCredit($seller->id, app(AdPricing::class)->monthlyCredit('red'),
            \App\Services\Ads\AdClock::endOfMonth(), \App\Services\Ads\AdClock::now()->format('Y-m'), 'test');
        Sanctum::actingAs($seller);
        $id = $this->postJson('/api/seller/payment-requests/plan-upgrade', ['plan' => 'black'])->assertCreated()->json('data.id');
        $price = (float) \App\Models\SubscriptionPlan::forSlug('black')->price_monthly;

        $admin = $this->makeUser('admin');
        Sanctum::actingAs($admin);
        $res = $this->postJson("/api/admin/payment-requests/{$id}/approve", ['payment_method' => 'bank_transfer', 'transaction_reference' => 'VIR-42'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertEquals($price, $res->json('data.amount_received'));

        $this->assertSame('black', SellerApplication::where('user_id', $seller->id)->value('plan'));
        $sub = SellerSubscription::where('user_id', $seller->id)->firstOrFail();
        $this->assertSame(['black', 'active', 'monthly'], [$sub->current_plan, $sub->status, $sub->billing_period]);
        $this->assertSame(today()->toDateString(), $sub->billing_cycle_start->toDateString());
        $this->assertSame(today()->addDays(30)->toDateString(), $sub->billing_cycle_end->toDateString());

        $payment = SubscriptionPayment::findOrFail($res->json('data.subscription_payment_id'));
        $this->assertSame(['black', 'succeeded'], [$payment->plan, $payment->status]);
        $this->assertEquals($price, (float) $payment->amount);
        $this->assertStringContainsString('VIR-42', $payment->notes);

        // Black Pepper's monthly ad credit applies now (topped up from Red's), as credit — not cash.
        $wallet = AdWallet::where('seller_id', $seller->id)->first();
        $this->assertEquals(app(AdPricing::class)->monthlyCredit('black'), (float) $wallet->credit_balance);
        $this->assertEquals(0, (float) $wallet->balance);

        Notification::assertSentTo($seller, PaymentRequestDecided::class, fn ($n) =>
            str_contains($n->toDatabase($seller)['body'], today()->addDays(30)->translatedFormat('j F Y'))
            && $n->toDatabase($seller)['link'] === '/seller/subscription');
        $this->assertGreaterThanOrEqual($price, $this->getJson('/api/admin/finance/overview?period=today')->json('data.kpis.subscription_revenue'));
        $this->postJson("/api/admin/payment-requests/{$id}/approve", ['payment_method' => 'bank_transfer'])->assertStatus(409);
        $this->assertSame(1, SubscriptionPayment::where('user_id', $seller->id)->count());
    }

    public function test_reject_needs_a_reason_and_tells_the_seller(): void
    {
        $seller = $this->payingSeller();
        Sanctum::actingAs($seller);
        $id = $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 30])->json('data.id');

        Sanctum::actingAs($this->makeUser('admin'));
        $this->postJson("/api/admin/payment-requests/{$id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/payment-requests/{$id}/reject", ['reason' => 'Aucun virement reçu'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Aucun virement reçu');

        Notification::assertSentTo($seller, PaymentRequestDecided::class, function ($n) use ($seller) {
            $mail = $n->toMail($seller)->toArray();
            return str_contains(implode(' ', $mail['introLines']), 'Aucun virement reçu');
        });
        $this->postJson("/api/admin/payment-requests/{$id}/approve", ['payment_method' => 'cash'])->assertStatus(409);
        $this->assertEquals(0, (float) (AdWallet::where('seller_id', $seller->id)->value('balance') ?? 0));
    }

    // ── Access rules ────────────────────────────────────────────────────────

    public function test_sellers_cannot_decide_or_see_other_sellers_requests(): void
    {
        $alice = $this->payingSeller();
        $bob   = $this->payingSeller();
        Sanctum::actingAs($alice);
        $id = $this->postJson('/api/seller/payment-requests/wallet-top-up', ['amount' => 40])->json('data.id');

        $this->postJson("/api/admin/payment-requests/{$id}/approve", ['payment_method' => 'd17'])->assertForbidden();
        $this->getJson('/api/admin/payment-requests')->assertForbidden();
        $this->postJson('/api/admin/payment-requests/direct/wallet-top-up', ['seller_id' => $alice->id, 'amount' => 10, 'payment_method' => 'cash'])->assertForbidden();

        Sanctum::actingAs($bob);
        $this->getJson("/api/seller/payment-requests/{$id}")->assertNotFound();
        $this->postJson("/api/seller/payment-requests/{$id}/cancel")->assertNotFound();
        $this->assertNotContains($id, array_column($this->getJson('/api/seller/payment-requests')->json('data'), 'id'));

        Sanctum::actingAs($alice);
        $this->postJson("/api/seller/payment-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/seller/payment-requests/{$id}/cancel")->assertStatus(409);

        Sanctum::actingAs($this->makeUser('admin'));
        $this->postJson("/api/admin/payment-requests/{$id}/approve", ['payment_method' => 'd17'])->assertStatus(409);
        $this->assertEquals(0, (float) (AdWallet::where('seller_id', $alice->id)->value('balance') ?? 0));
    }

    // ── Admin: list & direct actions ────────────────────────────────────────

    public function test_admin_list_filters_and_search(): void
    {
        $seller = $this->payingSeller('free');
        Sanctum::actingAs($seller);
        $ref = $this->postJson('/api/seller/payment-requests/plan-upgrade', ['plan' => 'red'])->json('data.reference');

        Sanctum::actingAs($this->makeUser('admin'));
        foreach (["search={$ref}", 'search=' . urlencode("l'Étoile"), 'search=22333444', 'search=' . urlencode('Hédi')] as $q) {
            $this->assertContains($ref, array_column($this->getJson("/api/admin/payment-requests?{$q}&seller_id={$seller->id}")->assertOk()->json('data'), 'reference'), $q);
        }
        $row = $this->getJson("/api/admin/payment-requests?type=plan_upgrade&status=pending&date_from=" . today()->toDateString() . "&seller_id={$seller->id}")
            ->assertOk()->json('data.0');
        $this->assertSame($ref, $row['reference']);
        $this->assertStringStartsWith('https://wa.me/21622333444?text=', $row['contact_url']);   // the seller's own number
        $this->assertSame('+216 22 333 444', $row['seller']['phone']);
        $this->assertSame([], $this->getJson("/api/admin/payment-requests?type=wallet_topup&seller_id={$seller->id}")->json('data'));
        $this->assertSame([], $this->getJson("/api/admin/payment-requests?status=approved&seller_id={$seller->id}")->json('data'));
    }

    public function test_direct_admin_top_up_and_plan_change_create_the_same_records(): void
    {
        $seller = $this->payingSeller('free');
        $admin  = $this->makeUser('admin');
        Sanctum::actingAs($admin);

        $top = $this->postJson('/api/admin/payment-requests/direct/wallet-top-up', [
            'seller_id' => $seller->id, 'amount' => 80, 'payment_method' => 'cash', 'note' => 'payé au bureau',
        ])->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.source', 'admin');
        $this->assertSame(['created', 'approved'], array_column($top->json('data.logs'), 'action'));
        $this->assertEquals(80, (float) AdWallet::where('seller_id', $seller->id)->value('balance'));
        $this->assertSame(1, AdTopUp::where('seller_id', $seller->id)->where('status', 'paid')->where('gateway', 'whatsapp')->count());

        $this->postJson('/api/admin/payment-requests/direct/plan-change', [
            'seller_id' => $seller->id, 'plan' => 'free', 'billing_period' => 'monthly', 'payment_method' => 'cash',
        ])->assertStatus(422)->assertJsonPath('code', 'PLAN_UNAVAILABLE');
        $this->postJson('/api/admin/payment-requests/direct/plan-change', [
            'seller_id' => $seller->id, 'plan' => 'red', 'billing_period' => 'monthly', 'payment_method' => 'd17', 'transaction_reference' => 'D17-1',
        ])->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.requested_plan.slug', 'red');
        $this->assertSame('red', SellerSubscription::where('user_id', $seller->id)->value('current_plan'));
        $this->assertSame(1, SubscriptionPayment::where('user_id', $seller->id)->where('status', 'succeeded')->count());
        Notification::assertSentToTimes($seller, PaymentRequestDecided::class, 2);
    }
}
