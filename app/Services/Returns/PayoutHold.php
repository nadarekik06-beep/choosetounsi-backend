<?php

namespace App\Services\Returns;

use App\Models\Complaint;
use App\Models\User;
use App\Notifications\Returns\SettlementDraftChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Returns v2: from the moment the courier pays the client in cash at pick-up until
 * the inspection (or the admin's dispute decision), the payout of the returned
 * parcel is FROZEN: no settlement batch may pick it up and both finance pages show
 * it as "awaiting return".
 *
 * The hold is on the whole seller_order (a settlement batch links whole parcels, it
 * has no per-item split). It starts when complaints.payout_frozen_at is set and ends
 * with payout_released_at.
 */
class PayoutHold
{
    /** Seller-order ids with an active hold (optionally one seller's). @return int[] */
    public static function sellerOrderIds(?int $sellerId = null): array
    {
        return DB::table('complaints as c')
            ->join('complaint_items as ci', 'ci.complaint_id', '=', 'c.id')
            ->join('order_items as oi', 'oi.id', '=', 'ci.order_item_id')
            ->whereNotNull('c.payout_frozen_at')
            ->whereNull('c.payout_released_at')
            ->whereNotNull('oi.seller_order_id')
            ->when($sellerId, fn ($q) => $q->where('c.seller_id', $sellerId))
            ->distinct()
            ->pluck('oi.seller_order_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** SQL boolean for a seller_orders alias: 1 while the parcel's payout is frozen. */
    public static function heldSql(string $alias = 'so'): string
    {
        return "EXISTS (SELECT 1 FROM complaints hc
            JOIN complaint_items hci ON hci.complaint_id = hc.id
            JOIN order_items hoi ON hoi.id = hci.order_item_id
            WHERE hoi.seller_order_id = {$alias}.id AND hc.payout_frozen_at IS NOT NULL AND hc.payout_released_at IS NULL)";
    }

    public static function isHeld(int $sellerOrderId): bool
    {
        return in_array($sellerOrderId, self::sellerOrderIds(), true);
    }

    /** Starts the hold and pulls the parcel(s) out of any DRAFT settlement. Runs in the caller's transaction. */
    public static function freeze(Complaint $c, ?User $admin = null): void
    {
        $c->forceFill(['payout_frozen_at' => now(), 'payout_released_at' => null])->save();

        $soIds = DB::table('complaint_items as ci')
            ->join('order_items as oi', 'oi.id', '=', 'ci.order_item_id')
            ->where('ci.complaint_id', $c->id)->whereNotNull('oi.seller_order_id')
            ->distinct()->pluck('oi.seller_order_id');

        foreach ($soIds as $soId) {
            $so = DB::table('seller_orders')->where('id', $soId)->lockForUpdate()->first();
            if (!$so || !$so->settlement_batch_id) {
                continue;
            }
            $batch = DB::table('settlement_batches')->where('id', $so->settlement_batch_id)->lockForUpdate()->first();
            if (!$batch || $batch->status !== 'draft') {
                continue;   // confirmed / paid batches are history: a debit is raised at inspection instead
            }

            DB::table('seller_orders')->where('id', $soId)->update(['settlement_batch_id' => null, 'updated_at' => now()]);
            $remaining = DB::table('seller_orders')->where('settlement_batch_id', $batch->id)->count();
            $deleted = false;

            if ($remaining === 0) {
                // Nothing left to pay in this draft: its pending debits go back to the pool, the draft disappears
                DB::table('seller_adjustments')->where('settlement_batch_id', $batch->id)
                    ->update(['settlement_batch_id' => null, 'updated_at' => now()]);
                DB::table('settlement_batches')->where('id', $batch->id)->delete();
                $deleted = true;
            } else {
                Settlements::recomputeDraft((int) $batch->id);
            }

            $orderNumber = (string) (DB::table('orders')->where('id', $c->order_id)->value('order_number') ?? $c->order_id);
            try {
                Notification::send(
                    User::where('role', 'admin')->where('is_active', true)->get(),
                    new SettlementDraftChangedNotification($orderNumber, (string) $batch->batch_reference, $deleted, $c->id)
                );
            } catch (\Throwable $e) {
                Log::error('[PayoutHold] draft notification failed: ' . $e->getMessage());
            }
            $c->loadMissing([]);
        }
    }

    public static function release(Complaint $c): void
    {
        if ($c->payout_frozen_at && !$c->payout_released_at) {
            $c->forceFill(['payout_released_at' => now()])->save();
        }
    }
}
