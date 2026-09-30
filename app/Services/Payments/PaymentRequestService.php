<?php

namespace App\Services\Payments;

use App\Exceptions\Payments\PaymentRequestViolation;
use App\Models\AdTopUp;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestLog;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\Sponsorship;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\PaymentRequestDecided;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdPricing;
use App\Services\Ads\AdWalletService;
use App\Services\Ads\SponsorshipService;
use App\Services\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manual (WhatsApp) payments for ad-wallet top-ups and plan upgrades.
 *
 *   seller:  requestTopUp / requestPlanUpgrade → pending request + pre-filled WhatsApp message
 *            cancel (own pending request)
 *   admin:   approve → wallet credited as real money (AdTopUp + ledger row, once) or plan
 *            switched with its benefits (SubscriptionPayment + new billing cycle + ad credit)
 *            reject (reason required)
 *            directTopUp / directPlanChange → the same records, approved at once
 *
 * Deciding locks the request row, so a double click or two admins at once can
 * only settle it once; the loser gets ALREADY_DECIDED (409). Every step is logged.
 */
class PaymentRequestService
{
    private const REF_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I

    public function __construct(
        private ManualPaymentSettings $settings,
        private AdWalletService $wallets,
        private SubscriptionService $subscriptions,
        private SponsorshipService $campaigns,
        private AdPricing $pricing,
    ) {}

    // ── Seller ──────────────────────────────────────────────────────────────

    public function requestTopUp(User $seller, float $amount): PaymentRequest
    {
        $this->assertEnabled();
        $app    = $this->sellerApplication($seller);
        $amount = round($amount, 3);
        $cfg    = $this->settings->all();

        if ($amount + 0.0005 < $cfg['min_top_up'] || $amount - 0.0005 > $cfg['max_top_up']) {
            throw PaymentRequestViolation::make('amount_out_of_range', [
                'min' => Money::dt($cfg['min_top_up']), 'max' => Money::dt($cfg['max_top_up']),
            ]);
        }
        $pending = PaymentRequest::where('seller_id', $seller->id)->where('type', PaymentRequest::TYPE_WALLET_TOPUP)
            ->where('status', PaymentRequest::STATUS_PENDING)->count();
        if ($pending >= (int) config('payments.max_pending_top_ups', 3)) {
            throw PaymentRequestViolation::make('too_many_pending', ['count' => $pending], 409);
        }

        return $this->create($seller, $app, [
            'type'   => PaymentRequest::TYPE_WALLET_TOPUP,
            'amount' => $amount,
        ], $seller, 'seller');
    }

    public function requestPlanUpgrade(User $seller, string $planSlug, string $period = 'monthly'): PaymentRequest
    {
        $this->assertEnabled();
        $app  = $this->sellerApplication($seller);
        $plan = $this->payablePlan($planSlug, $period);
        $sub  = $this->subscriptions->getOrCreateSubscription($app);

        if ($sub->isSuspended()) {
            throw PaymentRequestViolation::make('subscription_suspended', [], 403);
        }
        $renewal = $sub->current_plan === $plan->slug;   // same paid plan (or its trial) = pay / renew it
        if (!$renewal && !$sub->isUpgrade($plan->slug)) {
            throw PaymentRequestViolation::make('not_an_upgrade', ['plan' => $plan->name]);
        }

        $open = PaymentRequest::where('seller_id', $seller->id)->where('type', PaymentRequest::TYPE_PLAN_UPGRADE)
            ->where('status', PaymentRequest::STATUS_PENDING)->first();
        if ($open) {
            throw PaymentRequestViolation::make('upgrade_pending', ['reference' => $open->reference], 409,
                ['request_id' => $open->id, 'reference' => $open->reference]);
        }

        return $this->create($seller, $app, [
            'type'           => PaymentRequest::TYPE_PLAN_UPGRADE,
            'amount'         => round($plan->priceFor($period), 3),
            'current_plan'   => $sub->current_plan,
            'requested_plan' => $plan->slug,
            'billing_period' => $period,
        ], $seller, 'seller');
    }

    public function cancel(PaymentRequest $request, User $seller): PaymentRequest
    {
        return DB::transaction(function () use ($request, $seller) {
            $r = $this->lock($request);
            if ($r->seller_id !== $seller->id) {
                throw PaymentRequestViolation::make('not_found', [], 404);
            }
            $this->assertPending($r);
            $r->update(['status' => PaymentRequest::STATUS_CANCELLED, 'cancelled_at' => now()]);
            $this->log($r, $seller, 'seller', 'cancelled');
            return $r;
        });
    }

    // ── Admin ───────────────────────────────────────────────────────────────

    /**
     * @param array{payment_method: string, amount_received?: float|null, transaction_reference?: ?string, note?: ?string} $data
     */
    public function approve(PaymentRequest $request, User $admin, array $data): PaymentRequest
    {
        $r = DB::transaction(function () use ($request, $admin, $data) {
            $r = $this->lock($request);
            $this->assertPending($r);

            $received = round((float) ($data['amount_received'] ?? $r->amount), 3);
            $decision = [
                'amount_received'       => $received,
                'payment_method'        => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'admin_note'            => $data['note'] ?? null,
            ];

            $made = $r->isTopUp() ? $this->creditWallet($r, $admin, $decision) : $this->activatePlan($r, $admin, $decision);

            $r->update($decision + $made + [
                'status'     => PaymentRequest::STATUS_APPROVED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ]);
            $this->log($r, $admin, 'admin', 'approved', array_filter($decision + $made, fn ($v) => $v !== null));
            return $r;
        });

        if ($r->isTopUp()) {
            $this->resumeWalletCampaigns($r->seller_id);
        }
        $this->notify($r);
        return $r->fresh();
    }

    public function reject(PaymentRequest $request, User $admin, string $reason): PaymentRequest
    {
        $r = DB::transaction(function () use ($request, $admin, $reason) {
            $r = $this->lock($request);
            $this->assertPending($r);
            $r->update([
                'status'           => PaymentRequest::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'decided_by'       => $admin->id,
                'decided_at'       => now(),
            ]);
            $this->log($r, $admin, 'admin', 'rejected', ['reason' => $reason]);
            return $r;
        });

        $this->notify($r);
        return $r->fresh();
    }

    /** Admin records a payment received outside any request: same records and logs, approved at once (all or nothing). */
    public function directTopUp(User $seller, User $admin, array $data): PaymentRequest
    {
        $app    = $this->sellerApplication($seller);
        $amount = round((float) $data['amount'], 3);
        if ($amount <= 0) {
            throw PaymentRequestViolation::make('invalid_amount');
        }

        return DB::transaction(fn () => $this->approve(
            $this->create($seller, $app, ['type' => PaymentRequest::TYPE_WALLET_TOPUP, 'amount' => $amount, 'source' => 'admin'], $admin, 'admin'),
            $admin, ['amount_received' => $amount] + $data
        ));
    }

    public function directPlanChange(User $seller, User $admin, array $data): PaymentRequest
    {
        $app    = $this->sellerApplication($seller);
        $period = $data['billing_period'] ?? 'monthly';
        $plan   = $this->payablePlan($data['plan'], $period);
        $sub    = $this->subscriptions->getOrCreateSubscription($app);
        if ($sub->isSuspended()) {
            throw PaymentRequestViolation::make('subscription_suspended', [], 403);
        }

        $price = round((float) ($data['amount'] ?? $plan->priceFor($period)), 3);

        return DB::transaction(fn () => $this->approve(
            $this->create($seller, $app, [
                'type'           => PaymentRequest::TYPE_PLAN_UPGRADE,
                'source'         => 'admin',
                'amount'         => $price,
                'current_plan'   => $sub->current_plan,
                'requested_plan' => $plan->slug,
                'billing_period' => $period,
            ], $admin, 'admin'),
            $admin, ['amount_received' => $price] + $data
        ));
    }

    // ── Links ───────────────────────────────────────────────────────────────

    /** The seller → ChooseTounsi chat with the request's message pre-filled. */
    public function whatsappUrl(PaymentRequest $r): ?string
    {
        return WhatsApp::link($this->settings->whatsappDigits(), $r->message);
    }

    /** Admin → seller chat (the seller's own phone number). */
    public function sellerContactUrl(PaymentRequest $r): ?string
    {
        $digits = WhatsApp::normalizePhone($r->seller_phone);
        return $digits ? WhatsApp::link($digits, "Bonjour {$r->seller_name}, concernant votre demande {$r->reference} sur ChooseTounsi.") : null;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function create(User $seller, SellerApplication $app, array $attrs, User $actor, string $role): PaymentRequest
    {
        $wallet = $this->wallets->walletFor($seller->id);
        $base = $attrs + [
            'source'         => 'seller',
            'status'         => PaymentRequest::STATUS_PENDING,
            'seller_id'      => $seller->id,
            'store_name'     => $app->business_name,
            'seller_name'    => $app->full_name ?: $seller->name,
            'seller_email'   => $seller->email,
            'seller_phone'   => $app->phone_number,
            'wallet_balance' => round((float) $wallet->balance, 3),
        ];

        for ($attempt = 1; ; $attempt++) {
            $base['reference'] = $this->newReference();
            $base['message']   = $this->message($base, $seller);
            try {
                $r = DB::transaction(function () use ($base, $actor, $role) {
                    $r = PaymentRequest::create($base);
                    $this->log($r, $actor, $role, 'created', array_filter([
                        'amount' => (float) $r->amount, 'plan' => $r->requested_plan, 'period' => $r->billing_period, 'source' => $r->source,
                    ], fn ($v) => $v !== null));
                    return $r;
                });
                return $r;
            } catch (QueryException $e) {
                // Reference collision (32^5 codes): draw another one.
                if (($e->errorInfo[1] ?? null) !== 1062 || $attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** Pre-filled WhatsApp text, from the admin-editable French template. */
    private function message(array $r, User $seller): string
    {
        $isTopUp  = $r['type'] === PaymentRequest::TYPE_WALLET_TOPUP;
        $template = (string) $this->settings->get($isTopUp ? 'template_wallet_topup' : 'template_plan_upgrade');
        $plan     = fn (?string $slug) => $slug ? SubscriptionPlan::forSlug($slug)->name : '—';

        return WhatsApp::render($template, [
            'reference'      => $r['reference'],
            'type'           => $isTopUp ? 'Recharge du portefeuille publicitaire' : 'Changement de plan',
            'amount'         => Money::plain($r['amount']),
            'price'          => Money::plain($r['amount']),
            'store'          => $r['store_name'] ?: '—',
            'seller_name'    => $r['seller_name'] ?: '—',
            'seller_id'      => $seller->id,
            'email'          => $r['seller_email'] ?: '—',
            'phone'          => $r['seller_phone'] ?: '—',
            'balance'        => Money::plain($r['wallet_balance']),
            'date'           => AdClock::now()->format('d/m/Y H:i'),
            'current_plan'   => $plan($r['current_plan'] ?? null),
            'requested_plan' => $plan($r['requested_plan'] ?? null),
            'period'         => ($r['billing_period'] ?? null) === 'yearly' ? 'annuel' : 'mensuel',
        ]);
    }

    /** @return array{ad_top_up_id: int} */
    private function creditWallet(PaymentRequest $r, User $admin, array $decision): array
    {
        if ($decision['amount_received'] <= 0) {
            throw PaymentRequestViolation::make('invalid_amount');
        }
        $meta = array_filter([
            'payment_request_id'    => $r->id,
            'payment_method'        => $decision['payment_method'],
            'transaction_reference' => $decision['transaction_reference'],
            'note'                  => $decision['admin_note'],
            'requested_amount'      => (float) $r->amount,
        ], fn ($v) => $v !== null);

        $topUp = AdTopUp::create([
            'seller_id' => $r->seller_id,
            'amount'    => $decision['amount_received'],
            'gateway'   => 'whatsapp',
            'status'    => AdTopUp::STATUS_PENDING,
            'reference' => $r->reference,
            'meta'      => $meta,
        ]);
        $topUp = $this->wallets->settleTopUp($topUp, true, null, [], $admin->id);

        return ['ad_top_up_id' => $topUp->id];
    }

    /** @return array{subscription_payment_id: int} */
    private function activatePlan(PaymentRequest $r, User $admin, array $decision): array
    {
        if ($decision['amount_received'] < 0) {
            throw PaymentRequestViolation::make('invalid_amount');
        }
        $app    = $this->sellerApplication($r->seller);
        $period = $r->billing_period ?? 'monthly';
        $plan   = $this->payablePlan($r->requested_plan, $period);
        $sub    = SellerSubscription::where('seller_application_id', $app->id)->first();
        if ($sub?->isSuspended()) {
            throw PaymentRequestViolation::make('subscription_suspended', [], 403);
        }

        $payment = SubscriptionPayment::create([
            'user_id'  => $r->seller_id,
            'plan'     => $plan->slug,
            'amount'   => $decision['amount_received'],
            'currency' => 'TND',
            'status'   => 'succeeded',
            'notes'    => trim("WhatsApp {$r->reference} · {$decision['payment_method']}"
                . ($decision['transaction_reference'] ? " · {$decision['transaction_reference']}" : '')),
        ]);

        $this->subscriptions->activatePaidPlan($app, $plan->slug, $period, $payment, $admin, "Demande {$r->reference}");

        // The plan's monthly free ad credit starts now, not on the 1st of next month.
        if ($plan->hasFeature('sponsorships')) {
            $this->wallets->ensureMonthlyCredit(
                $r->seller_id, $this->pricing->monthlyCredit($plan->tierKey()),
                AdClock::endOfMonth(), AdClock::now()->format('Y-m'), "pr{$r->id}"
            );
        }

        return ['subscription_payment_id' => $payment->id];
    }

    /** Campaigns paused for an empty wallet run again once the money is in (existing auto-resume). */
    private function resumeWalletCampaigns(int $sellerId): void
    {
        Sponsorship::where('seller_id', $sellerId)->where('status', Sponsorship::STATUS_PAUSED)
            ->where('paused_reason', Sponsorship::PAUSE_WALLET_EMPTY)->orderBy('id')
            ->each(function (Sponsorship $c) {
                try {
                    $this->campaigns->resumeAutomatically($c);
                } catch (\Throwable $e) {
                    Log::warning("[payment-requests] resume campaign #{$c->id}: " . $e->getMessage());
                }
            });
    }

    private function notify(PaymentRequest $r): void
    {
        try {
            $r->seller?->notify(new PaymentRequestDecided($r));
        } catch (\Throwable $e) {
            Log::warning("[payment-requests] notify #{$r->id}: " . $e->getMessage());
        }
    }

    private function payablePlan(?string $slug, string $period): SubscriptionPlan
    {
        $plan = SubscriptionPlan::where('slug', (string) $slug)->first();
        if (!$plan || $plan->isFree() || $plan->isArchived() || !$plan->is_active) {
            throw PaymentRequestViolation::make('plan_unavailable');
        }
        if ($period === 'yearly' && $plan->price_yearly === null) {
            throw PaymentRequestViolation::make('no_yearly_price', ['plan' => $plan->name]);
        }
        return $plan;
    }

    private function sellerApplication(User $seller): SellerApplication
    {
        $app = SellerApplication::where('user_id', $seller->id)->where('status', 'approved')->first();
        if (!$app) {
            throw PaymentRequestViolation::make('not_seller', [], 403);
        }
        return $app;
    }

    private function assertEnabled(): void
    {
        if (!$this->settings->enabled()) {
            throw PaymentRequestViolation::make('method_disabled', [], 403);
        }
    }

    private function assertPending(PaymentRequest $r): void
    {
        if (!$r->isPending()) {
            throw PaymentRequestViolation::make('already_decided', ['status' => __("payments.status.{$r->status}")], 409,
                ['status' => $r->status]);
        }
    }

    private function lock(PaymentRequest $r): PaymentRequest
    {
        return PaymentRequest::whereKey($r->id)->lockForUpdate()->firstOrFail();
    }

    private function newReference(): string
    {
        do {
            $code = 'CT-';
            for ($i = 0; $i < 5; $i++) {
                $code .= self::REF_ALPHABET[random_int(0, strlen(self::REF_ALPHABET) - 1)];
            }
        } while (PaymentRequest::where('reference', $code)->exists());
        return $code;
    }

    private function log(PaymentRequest $r, ?User $actor, string $role, string $action, array $data = []): void
    {
        PaymentRequestLog::create([
            'payment_request_id' => $r->id,
            'actor_id'           => $actor?->id,
            'actor_role'         => $role,
            'action'             => $action,
            'data'               => $data ?: null,
        ]);
    }
}
