<?php
// app/Services/WalletService.php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Deduct wallet balance for an order payment.
     * Wrapped in a DB transaction — balance never goes negative.
     *
     * @throws \Exception if balance is insufficient
     */
    public function deductForOrder(User $user, Order $order): WalletTransaction
    {
        return DB::transaction(function () use ($user, $order) {
            // Re-fetch with lock to prevent race conditions
            $user = User::lockForUpdate()->findOrFail($user->id);

            $amount = (float) $order->total_amount;

            if ((float) $user->wallet_balance < $amount) {
                throw new \Exception('Insufficient wallet balance.');
            }

            $newBalance = round((float) $user->wallet_balance - $amount, 3);

            $user->forceFill(['wallet_balance' => $newBalance])->save(); // not mass-assignable on purpose

            return WalletTransaction::create([
                'user_id'       => $user->id,
                'amount'        => -$amount,  // negative = debit
                'type'          => 'debit',
                'reason'        => 'order_payment',
                'order_id'      => $order->id,
                'note'          => "Payment for order #{$order->order_number}",
                'balance_after' => $newBalance,
            ]);
        });
    }

    /**
     * Refund wallet balance when an order is cancelled.
     */
    public function refundOrder(User $user, Order $order): WalletTransaction
    {
        return DB::transaction(function () use ($user, $order) {
            $user       = User::lockForUpdate()->findOrFail($user->id);
            $amount     = (float) $order->total_amount;
            $newBalance = round((float) $user->wallet_balance + $amount, 3);

            $user->forceFill(['wallet_balance' => $newBalance])->save(); // not mass-assignable on purpose

            return WalletTransaction::create([
                'user_id'       => $user->id,
                'amount'        => $amount,   // positive = credit
                'type'          => 'credit',
                'reason'        => 'order_refund',
                'order_id'      => $order->id,
                'note'          => "Refund for cancelled order #{$order->order_number}",
                'balance_after' => $newBalance,
            ]);
        });
    }

    /**
     * Refund of a completed return, credited to the client's wallet.
     */
    public function creditReturnRefund(User $user, Order $order, float $amount, string $reference): WalletTransaction
    {
        return DB::transaction(function () use ($user, $order, $amount, $reference) {
            $user       = User::lockForUpdate()->findOrFail($user->id);
            $amount     = round($amount, 3);
            $newBalance = round((float) $user->wallet_balance + $amount, 3);

            $user->forceFill(['wallet_balance' => $newBalance])->save(); // not mass-assignable on purpose

            return WalletTransaction::create([
                'user_id'       => $user->id,
                'amount'        => $amount,
                'type'          => 'credit',
                'reason'        => 'return_refund',
                'order_id'      => $order->id,
                'note'          => "Refund of return {$reference} (order #{$order->order_number})",
                'balance_after' => $newBalance,
            ]);
        });
    }

    /**
     * Admin top-up.
     */
    public function topUp(User $user, float $amount, string $note = ''): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $note) {
            $user       = User::lockForUpdate()->findOrFail($user->id);
            $newBalance = round((float) $user->wallet_balance + $amount, 3);

            $user->forceFill(['wallet_balance' => $newBalance])->save(); // not mass-assignable on purpose

            return WalletTransaction::create([
                'user_id'       => $user->id,
                'amount'        => $amount,
                'type'          => 'credit',
                'reason'        => 'admin_top_up',
                'note'          => $note ?: "Admin top-up of {$amount} DT",
                'balance_after' => $newBalance,
            ]);
        });
    }
}