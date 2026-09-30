<?php

namespace App\Services\Ads;

use App\Exceptions\Ads\AdRuleViolation;
use App\Exceptions\Ads\InsufficientAdFunds;
use App\Models\AdTopUp;
use App\Models\AdWallet;
use App\Models\AdWalletTransaction as Tx;
use App\Models\Sponsorship;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of ad wallets. Every balance change is one ledger row, made
 * inside a transaction with the wallet row locked (lockForUpdate), so
 * concurrent clicks can never overdraw it.
 *
 * Two pots: free monthly credit (spent first, expires at month end) and the
 * paid balance. Click charges roll up into one ledger row per campaign per
 * Africa/Tunis day.
 */
class AdWalletService
{
    public function __construct(private AdSettings $settings) {}

    public function walletFor(int $sellerId): AdWallet
    {
        return AdWallet::firstOrCreate(['seller_id' => $sellerId]);
    }

    /** Credit (if not expired) + balance. */
    public function available(int $sellerId): float
    {
        return $this->walletFor($sellerId)->available();
    }

    // ── Money in ────────────────────────────────────────────────────────────

    public function topUp(int $sellerId, float $amount, ?string $reference = null, array $meta = [], ?int $by = null): Tx
    {
        $amount = $this->money($amount);
        if ($amount <= 0) {
            throw AdRuleViolation::make('invalid_amount');
        }

        return DB::transaction(fn () => $this->apply($this->lockedWallet($sellerId), Tx::TYPE_TOP_UP, $amount, 0, [
            'reference' => $reference, 'meta' => $meta ?: null, 'created_by' => $by,
        ]));
    }

    /**
     * Settle a top-up intent exactly once: pending → paid (one wallet transaction)
     * or failed/cancelled. Replayed callbacks and double admin clicks are no-ops.
     */
    public function settleTopUp(AdTopUp $topUp, bool $paid, ?string $reference = null, array $meta = [], ?int $by = null, string $failStatus = AdTopUp::STATUS_FAILED): AdTopUp
    {
        return DB::transaction(function () use ($topUp, $paid, $reference, $meta, $by, $failStatus) {
            $row = AdTopUp::whereKey($topUp->id)->lockForUpdate()->firstOrFail();
            if (!$row->isPending()) {
                return $row;
            }

            $meta = array_merge($row->meta ?? [], $meta);
            if (!$paid) {
                $row->update(['status' => $failStatus, 'meta' => $meta ?: null, 'settled_by' => $by]);
                return $row;
            }

            $tx = $this->apply($this->lockedWallet($row->seller_id), Tx::TYPE_TOP_UP, (float) $row->amount, 0, [
                'reference'  => 'top_up:' . $row->id,
                'meta'       => ['gateway' => $row->gateway, 'gateway_reference' => $reference ?? $row->reference],
                'created_by' => $by,
            ]);
            $row->update([
                'status'                => AdTopUp::STATUS_PAID,
                'paid_at'               => now(),
                'reference'             => $reference ?? $row->reference,
                'meta'                  => $meta ?: null,
                'wallet_transaction_id' => $tx->id,
                'settled_by'            => $by,
            ]);
            return $row;
        });
    }

    /**
     * Grant a plan's monthly credit for $period ("2026-10"): whatever credit is
     * left expires first, the new credit replaces it until $expiresAt.
     * Idempotent per seller and period.
     */
    public function grantMonthlyCredit(int $sellerId, float $amount, Carbon $expiresAt, string $period): ?Tx
    {
        $amount = $this->money($amount);

        return DB::transaction(function () use ($sellerId, $amount, $expiresAt, $period) {
            $wallet = $this->lockedWallet($sellerId);

            $already = Tx::where('seller_id', $sellerId)->where('type', Tx::TYPE_MONTHLY_CREDIT)
                ->where('reference', "credit:{$period}")->exists();
            if ($already) {
                return null;
            }

            $this->expireCredit($wallet, force: true);
            if ($amount <= 0) {
                return null;
            }

            $wallet->credit_expires_at = $expiresAt;
            return $this->apply($wallet, Tx::TYPE_MONTHLY_CREDIT, 0, $amount, [
                'reference' => "credit:{$period}",
                'meta'      => ['expires_at' => $expiresAt->toIso8601String()],
            ]);
        });
    }

    /**
     * A plan paid mid-month: bring this month's free credit up to the new plan's
     * $amount. Nothing granted yet this period → the full amount becomes this month's
     * grant ("credit:{period}", so the monthly job won't grant it again); otherwise only
     * the difference is added. Never takes credit away; idempotent per $tag.
     */
    public function ensureMonthlyCredit(int $sellerId, float $amount, Carbon $expiresAt, string $period, string $tag): ?Tx
    {
        $amount = $this->money($amount);
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($sellerId, $amount, $expiresAt, $period, $tag) {
            $wallet = $this->lockedWallet($sellerId);
            $grants = Tx::where('seller_id', $sellerId)->where('type', Tx::TYPE_MONTHLY_CREDIT)
                ->where(fn ($q) => $q->where('reference', "credit:{$period}")->orWhere('reference', 'like', "credit:{$period}:%"));

            if ((clone $grants)->where('reference', "credit:{$period}:{$tag}")->exists()) {
                return null;
            }
            $hasGrant = (clone $grants)->where('reference', "credit:{$period}")->exists();
            $diff     = $this->money($amount - (float) (clone $grants)->sum('credit_amount'));
            if ($diff <= 0) {
                return null;
            }

            $this->expireCredit($wallet);
            $wallet->credit_expires_at = $expiresAt;
            return $this->apply($wallet, Tx::TYPE_MONTHLY_CREDIT, 0, $diff, [
                'reference' => $hasGrant ? "credit:{$period}:{$tag}" : "credit:{$period}",
                'meta'      => ['expires_at' => $expiresAt->toIso8601String(), 'plan_change' => $tag],
            ]);
        });
    }

    // ── Money out ───────────────────────────────────────────────────────────

    /**
     * Charge one click of $campaign: credit first, then balance. Never overdraws —
     * throws InsufficientAdFunds instead. The day's charges for a campaign roll up
     * into one click_charge ledger row.
     *
     * @return array{total: float, credit: float, paid: float, transaction: Tx}
     */
    public function charge(Sponsorship $campaign, float $amount): array
    {
        $amount = $this->money($amount);
        if ($amount <= 0) {
            throw AdRuleViolation::make('invalid_amount');
        }

        return DB::transaction(function () use ($campaign, $amount) {
            $wallet = $this->lockedWallet($campaign->seller_id);
            $this->expireCredit($wallet);

            $credit = (float) $wallet->credit_balance;
            $paid   = (float) $wallet->balance;
            if ($credit + $paid + 0.0005 < $amount) {
                throw InsufficientAdFunds::for($amount, $credit + $paid);
            }

            $fromCredit = $this->money(min($credit, $amount));
            $fromPaid   = $this->money($amount - $fromCredit);

            $wallet->credit_balance = $this->money($credit - $fromCredit);
            $wallet->balance        = $this->money($paid - $fromPaid);
            $wallet->save();

            $day = AdClock::today();
            $tx  = Tx::where('sponsorship_id', $campaign->id)->where('type', Tx::TYPE_CLICK_CHARGE)
                ->whereDate('rollup_date', $day)->lockForUpdate()->first();

            if ($tx) {
                $meta = $tx->meta ?? [];
                $meta['clicks'] = ($meta['clicks'] ?? 0) + 1;
                $tx->update([
                    'amount'        => $this->money((float) $tx->amount - $amount),
                    'credit_amount' => $this->money((float) $tx->credit_amount - $fromCredit),
                    'balance_after' => $wallet->balance,
                    'credit_after'  => $wallet->credit_balance,
                    'meta'          => $meta,
                ]);
            } else {
                $tx = Tx::create([
                    'seller_id'      => $campaign->seller_id,
                    'type'           => Tx::TYPE_CLICK_CHARGE,
                    'amount'         => -$amount,
                    'credit_amount'  => -$fromCredit,
                    'balance_after'  => $wallet->balance,
                    'credit_after'   => $wallet->credit_balance,
                    'sponsorship_id' => $campaign->id,
                    'rollup_date'    => $day,
                    'meta'           => ['clicks' => 1],
                ]);
            }

            return ['total' => $amount, 'credit' => $fromCredit, 'paid' => $fromPaid, 'transaction' => $tx];
        });
    }

    /**
     * Give money back: the paid part to the balance, the credit part to the credit
     * pot (dropped if that credit has expired in the meantime — it's never cash).
     */
    public function refund(int $sellerId, float $paid, float $credit, ?Sponsorship $campaign, string $reason, ?int $by = null): ?Tx
    {
        $paid   = $this->money(max(0, $paid));
        $credit = $this->money(max(0, $credit));
        if ($paid <= 0 && $credit <= 0) {
            return null;
        }

        return DB::transaction(function () use ($sellerId, $paid, $credit, $campaign, $reason, $by) {
            $wallet = $this->lockedWallet($sellerId);
            $this->expireCredit($wallet);

            $creditBack = $wallet->credit_expires_at !== null && $wallet->credit_expires_at->isPast() ? 0.0 : $credit;
            if ($paid <= 0 && $creditBack <= 0) {
                return null;
            }

            return $this->apply($wallet, Tx::TYPE_REFUND, $paid, $creditBack, [
                'sponsorship_id' => $campaign?->id,
                'reference'      => $reason,
                'meta'           => $creditBack < $credit ? ['credit_dropped' => $this->money($credit - $creditBack)] : null,
                'created_by'     => $by,
            ]);
        });
    }

    /**
     * Refund every click charge a campaign has paid (used when an admin rejects it).
     * Refunds already made for the campaign are subtracted, so it's safe to repeat.
     */
    public function refundCampaignCharges(Sponsorship $campaign, string $reason, ?int $by = null): ?Tx
    {
        $rows = Tx::where('sponsorship_id', $campaign->id)
            ->whereIn('type', [Tx::TYPE_CLICK_CHARGE, Tx::TYPE_REFUND])
            ->selectRaw('SUM(amount) AS total, SUM(credit_amount) AS credit')
            ->first();

        // Net of charges (negative) and earlier refunds (positive).
        $netTotal  = -(float) ($rows->total ?? 0);
        $netCredit = -(float) ($rows->credit ?? 0);

        return $this->refund($campaign->seller_id, $netTotal - $netCredit, $netCredit, $campaign, $reason, $by);
    }

    /** Manual correction by an admin (both pots; neither may go below zero). */
    public function adminAdjust(int $sellerId, float $balanceDelta, float $creditDelta, string $note, int $adminId, ?Carbon $creditExpiresAt = null): Tx
    {
        $balanceDelta = $this->money($balanceDelta);
        $creditDelta  = $this->money($creditDelta);
        if ($balanceDelta == 0 && $creditDelta == 0) {
            throw AdRuleViolation::make('invalid_amount');
        }

        return DB::transaction(function () use ($sellerId, $balanceDelta, $creditDelta, $note, $adminId, $creditExpiresAt) {
            $wallet = $this->lockedWallet($sellerId);
            $this->expireCredit($wallet);

            if ((float) $wallet->balance + $balanceDelta < 0 || (float) $wallet->credit_balance + $creditDelta < 0) {
                throw AdRuleViolation::make('adjust_below_zero');
            }
            if ($creditDelta > 0) {
                $wallet->credit_expires_at = $creditExpiresAt ?? AdClock::endOfMonth();
            }

            return $this->apply($wallet, Tx::TYPE_ADMIN_ADJUST, $balanceDelta, $creditDelta, [
                'reference' => 'admin', 'meta' => ['note' => $note], 'created_by' => $adminId,
            ]);
        });
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function lockedWallet(int $sellerId): AdWallet
    {
        AdWallet::query()->insertOrIgnore(['seller_id' => $sellerId, 'created_at' => now(), 'updated_at' => now()]);
        return AdWallet::where('seller_id', $sellerId)->lockForUpdate()->firstOrFail();
    }

    /** Zero out credit that has expired (or all credit when $force), with a ledger row. */
    private function expireCredit(AdWallet $wallet, bool $force = false): void
    {
        $credit = (float) $wallet->credit_balance;
        $expired = $wallet->credit_expires_at !== null && $wallet->credit_expires_at->isPast();
        if ($credit <= 0 || (!$expired && !$force)) {
            return;
        }
        $this->apply($wallet, Tx::TYPE_CREDIT_EXPIRY, 0, -$credit, ['reference' => 'credit_expiry']);
    }

    /** Move both pots of a locked wallet and write the matching ledger row. */
    private function apply(AdWallet $wallet, string $type, float $balanceDelta, float $creditDelta, array $attrs): Tx
    {
        $wallet->balance        = $this->money((float) $wallet->balance + $balanceDelta);
        $wallet->credit_balance = $this->money((float) $wallet->credit_balance + $creditDelta);
        if ((float) $wallet->credit_balance <= 0) {
            $wallet->credit_balance = 0;
        }
        $wallet->save();

        return Tx::create(array_merge([
            'seller_id'     => $wallet->seller_id,
            'type'          => $type,
            'amount'        => $this->money($balanceDelta + $creditDelta),
            'credit_amount' => $this->money($creditDelta),
            'balance_after' => $wallet->balance,
            'credit_after'  => $wallet->credit_balance,
        ], $attrs));
    }

    private function money(float $value): float
    {
        return round($value, 3);
    }
}
