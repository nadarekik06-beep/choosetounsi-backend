<?php

namespace App\Services\Returns;

use App\Events\ComplaintApproved;
use App\Models\Complaint;
use App\Models\ComplaintEvent;
use App\Models\ComplaintItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerAdjustment;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\Buyer\ComplaintNotification;
use App\Notifications\ComplaintCreatedNotification;
use App\Notifications\Returns\ReturnAdminNotification;
use App\Notifications\Returns\ReturnSellerNotification;
use App\Services\Notifications\BuyerNotifier;
use App\Services\PromotionService;
use App\Services\WalletService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The one place a return moves. Every step:
 *   - checks the transition (Complaint::TRANSITIONS) on a locked row,
 *   - writes a complaint_events row (who, when, note) — the timeline + audit,
 *   - runs in a DB transaction with its stock / money side effects,
 *   - notifies after commit (client in-app + e-mail at every key step).
 *
 * Money rules (Tunisia, mostly cash on delivery):
 *   - nothing is refunded before the item is physically back and inspected;
 *   - refund = what the client actually paid for the returned units (coupon
 *     share and flash price included), minus the return shipping when the
 *     client pays it ("other" reason = changed mind); the seller pays it when
 *     the item was wrong or defective;
 *   - cash on delivery → the courier checks the item against the proof
 *     photos and pays the client back in cash at pick-up, out of the cash
 *     he holds for the platform (refund_method 'cash'); the sale is reversed
 *     then, the shop still inspects it at reception (restock);
 *     paid online → after reception: card → original method (Stripe) or
 *     wallet; wallet → wallet; D17 → D17 / wallet / transfer.
 *   - on refund the sale is reversed on the order line and the sub-order
 *     (revenue, commission, payout); a sub-order already paid out gets a
 *     negative adjustment on the next settlement instead.
 */
class ReturnService
{
    public function __construct(private WalletService $wallet) {}

    // ── Creation ────────────────────────────────────────────────────────────

    /**
     * One return per shop: lines of several sellers become several returns.
     *
     * @param  array<int,int>      $quantities order_item_id => units to return
     * @param  UploadedFile[]      $images     proof photos (≥ 1)
     * @return Collection<int, Complaint>
     */
    public function create(User $client, Order $order, array $quantities, array $data, array $images): Collection
    {
        $lines = OrderItem::where('order_id', $order->id)
            ->whereIn('id', array_keys($quantities))
            ->with('sellerOrder')
            ->get()->keyBy('id');

        $paths = array_map(fn(UploadedFile $f) => $f->store('complaints', 'public'), $images);

        try {
            $complaints = $this->createRows($client, $order, $quantities, $data, $paths, $lines);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($paths);
            throw $e;
        }

        foreach ($complaints as $complaint) {
            $this->buyer($complaint, 'received');
            try {
                $creation = new ComplaintCreatedNotification($complaint, $client);
                if ($complaint->seller_id && ($seller = User::find($complaint->seller_id))) $seller->notify($creation);
                Notification::send($this->admins(), $creation);
            } catch (\Throwable $e) {
                Log::error('[Return] creation notification failed: ' . $e->getMessage());
            }
        }

        return $complaints;
    }

    private function createRows(User $client, Order $order, array $quantities, array $data, array $paths, Collection $lines): Collection
    {
        return DB::transaction(function () use ($client, $order, $quantities, $data, $paths, $lines) {
            $allLines = OrderItem::where('order_id', $order->id)->lockForUpdate()->get();
            $reserved = $this->reservedQuantities($order->id);

            $groups = $lines->groupBy(fn($l) => $l->seller_order_id ?? 0);
            $created = collect();

            foreach ($groups as $soId => $group) {
                $sellerId = $group->first()->sellerOrder?->seller_id
                    ?? DB::table('products')->where('id', $group->first()->product_id)->value('seller_id');

                $complaint = Complaint::create([
                    'user_id'         => $client->id,
                    'order_id'        => $order->id,
                    'seller_id'       => $sellerId,
                    'order_item_ids'  => $group->pluck('id')->map(fn($v) => (int) $v)->values()->all(),
                    'complaint_type'  => $data['complaint_type'],
                    'resolution_type' => Complaint::RESOLUTION_RETURN_REFUND,
                    'other_reason'    => $data['other_reason'] ?? null,
                    'description'     => $data['description'],
                    'image_path'      => $paths[0] ?? null,
                    'image_paths'     => $paths,
                    'status'          => Complaint::STATUS_REQUESTED,
                    'shipping_payer'  => in_array($data['complaint_type'], Complaint::SELLER_FAULT_TYPES, true) ? 'seller' : 'client',
                    'return_shipping_fee' => $this->returnShippingFee(),
                ]);
                $complaint->update(['reference' => Complaint::nextReference($complaint->id)]);

                $amount = 0.0;
                foreach ($group as $line) {
                    $qty = (int) $quantities[$line->id];
                    $left = (int) $line->quantity - (int) ($reserved[$line->id] ?? 0);
                    if ($qty < 1 || $qty > $left) {
                        throw new ReturnException(__('messages.complaint.quantity_too_high', ['item' => $line->product_name]));
                    }
                    $share = $this->share($line, $qty);
                    $amount += $share['net_amount'];
                    ComplaintItem::create(['complaint_id' => $complaint->id, 'order_item_id' => $line->id, 'quantity' => $qty] + $share);
                }

                // Whole shop's part of the order: every unit of every line of that sub-order
                $shopLines  = $allLines->where('seller_order_id', $soId ?: null);
                $isFull     = $shopLines->every(fn($l) => (int) ($quantities[$l->id] ?? 0) + (int) ($reserved[$l->id] ?? 0) >= (int) $l->quantity);
                $complaint->update([
                    'return_scope'  => $isFull ? 'full' : 'partial',
                    'items_amount'  => round($amount, 3),
                    'refund_amount' => $this->refundFor($complaint->fresh(), $amount),
                ]);

                $this->event($complaint, Complaint::STATUS_REQUESTED, $client, 'client', $data['description']);
                $created->push($complaint->fresh());
            }

            return $created;
        });
    }

    /** Units already in a live return, per order line (so they can't be returned twice). */
    public function reservedQuantities(int $orderId): array
    {
        return DB::table('complaint_items as ci')
            ->join('complaints as c', 'c.id', '=', 'ci.complaint_id')
            ->where('c.order_id', $orderId)
            ->whereIn('c.status', Complaint::BLOCKING_STATUSES)
            ->whereNull('c.finance_applied_at') // once refunded, the line's quantity already excludes them
            ->groupBy('ci.order_item_id')
            ->selectRaw('ci.order_item_id, SUM(ci.quantity) as qty')
            ->pluck('qty', 'order_item_id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    // ── Decisions ───────────────────────────────────────────────────────────

    public function sellerAccept(Complaint $complaint, User $seller, ?string $note): Complaint
    {
        $c = $this->move($complaint, Complaint::STATUS_SELLER_ACCEPTED, $seller, 'seller', $note, [
            'seller_note'       => $note ?? $complaint->seller_note,
            'seller_decision'   => 'approved',
            'seller_decided_at' => now(),
            'reviewed_at'       => now(),
        ]);
        $this->buyer($c, 'seller_accepted');
        $this->admin($c, 'seller_accepted');
        return $c;
    }

    public function sellerReject(Complaint $complaint, User $seller, string $reason, ?string $note): Complaint
    {
        $c = $this->move($complaint, Complaint::STATUS_SELLER_REJECTED, $seller, 'seller', $reason, [
            'seller_note'       => $note ?? $complaint->seller_note,
            'seller_decision'   => 'rejected',
            'rejection_reason'  => $reason,
            'seller_decided_at' => now(),
            'reviewed_at'       => now(),
        ]);
        $this->buyer($c, 'seller_rejected');
        return $c;
    }

    public function escalate(Complaint $complaint, User $client, ?string $note): Complaint
    {
        if (!$complaint->canEscalate()) {
            throw new ReturnException(__('messages.complaint.cannot_escalate'));
        }
        $c = $this->move($complaint, Complaint::STATUS_ESCALATED, $client, 'client', $note, [
            'escalated_at'    => now(),
            'escalation_note' => $note,
        ]);
        $this->buyer($c, 'escalated');
        $this->admin($c, 'escalated');
        return $c;
    }

    public function clientCancel(Complaint $complaint, User $client): Complaint
    {
        if ($complaint->status !== Complaint::STATUS_REQUESTED) {
            throw new ReturnException(__('messages.complaint.cannot_cancel'));
        }
        $c = $this->move($complaint, Complaint::STATUS_CANCELLED, $client, 'client', null, ['resolved_at' => now()]);
        $this->buyer($c, 'cancelled');
        return $c;
    }

    /** Admin approval (also overrides a seller refusal). Opens the pick-up. */
    public function adminApprove(Complaint $complaint, User $admin, ?string $note, ?string $shippingPayer = null): Complaint
    {
        if ($complaint->isExchange()) {
            throw new ReturnException('Exchange requests are no longer handled — the client should reorder.');
        }
        $extra = [
            'admin_decided_by' => $admin->id,
            'admin_decided_at' => now(),
            'admin_note'       => $note,
            'resolved_at'      => now(),
        ];
        if ($shippingPayer) {
            $extra['shipping_payer'] = $shippingPayer;
        }

        $c = DB::transaction(function () use ($complaint, $admin, $note, $extra) {
            $c = $this->move($complaint, Complaint::STATUS_ADMIN_APPROVED, $admin, 'admin', $note, $extra, null, false);
            $c->update(['refund_amount' => $this->refundFor($c, (float) $c->items_amount)]);
            return $c->fresh();
        });

        ComplaintApproved::dispatch($c);   // → pick-up task in the delivery app
        $this->buyer($c, 'approved');
        $this->seller($c, 'admin_approved');
        return $c->fresh();
    }

    /** Admin refusal (also confirms or overrides a seller acceptance). */
    public function adminReject(Complaint $complaint, User $admin, string $reason): Complaint
    {
        $c = $this->move($complaint, Complaint::STATUS_REJECTED, $admin, 'admin', $reason, [
            'rejection_reason' => $reason,
            'admin_decided_by' => $admin->id,
            'admin_decided_at' => now(),
            'resolved_at'      => now(),
        ]);
        $this->buyer($c, 'rejected');
        if ($complaint->seller_decision === 'approved') {
            $this->seller($c, 'rejected');
        }
        return $c;
    }

    public function adminCancel(Complaint $complaint, User $admin, string $reason): Complaint
    {
        $c = $this->move($complaint, Complaint::STATUS_CANCELLED, $admin, 'admin', $reason, [
            'admin_note'  => $reason,
            'resolved_at' => now(),
        ]);
        RefundTaskSync::cancel($c);
        $this->buyer($c, 'cancelled');
        $this->seller($c, 'cancelled');
        return $c;
    }

    /** Legacy exchange records: nothing more to do than close them. */
    public function closeLegacyExchange(Complaint $complaint, User $admin, ?string $note): Complaint
    {
        if (!$complaint->isExchange()) {
            throw new ReturnException('Only legacy exchange requests can be closed.');
        }
        return $this->move($complaint, Complaint::STATUS_CLOSED, $admin, 'admin', $note, ['resolved_at' => now()]);
    }

    // ── Pick-up ─────────────────────────────────────────────────────────────

    public function schedulePickup(Complaint $complaint, ?User $actor, string $role, ?string $note, array $meta = []): Complaint
    {
        $c = $this->move($complaint, Complaint::STATUS_PICKUP_SCHEDULED, $actor, $role, $note, [
            'pickup_scheduled_at' => now(),
            'pickup_note'         => $note,
        ], $meta ?: null);
        // Booked by the admin with an outside delivery company: the in-house
        // courier task nobody took is no longer needed
        if ($role === 'admin') {
            \App\Models\RefundDeliveryTask::where('complaint_id', $c->id)
                ->where('status', \App\Models\RefundDeliveryTask::STATUS_PENDING)->delete();
            if (!\App\Models\RefundDeliveryTask::where('complaint_id', $c->id)->exists()) {
                $c->update(['refund_task_id' => null, 'refund_status' => null]);
            }
        }
        $this->buyer($c, 'pickup_scheduled');
        $this->seller($c, 'pickup_scheduled');
        return $c;
    }

    public function markPickedUp(Complaint $complaint, ?User $actor, string $role, ?string $note = null, ?string $courier = null): Complaint
    {
        $cash = $this->isCashRefund($complaint);

        $c = DB::transaction(function () use ($complaint, $actor, $role, $note, $courier, $cash) {
            $c = $this->move($complaint, Complaint::STATUS_PICKED_UP, $actor, $role, $note, ['picked_up_at' => now()], null, false);
            return $cash ? $this->payCashAtPickup($c, $actor, $role, $courier ?? ($role === 'delivery' ? $actor?->name : null)) : $c;
        });

        if ($cash) {
            $this->buyer($c, 'refunded');
            $this->seller($c, 'refunded');
        } else {
            $this->buyer($c, 'picked_up');
        }
        return $c;
    }

    /** Cash on delivery: the courier pays the client back at pick-up. */
    public function isCashRefund(Complaint $complaint): bool
    {
        return ($complaint->order()->value('payment_method') ?? 'cod') === 'cod';
    }

    /** Amount the courier hands to the client (0 for orders paid online). */
    public function cashToPay(Complaint $complaint): float
    {
        return $this->isCashRefund($complaint) ? (float) $complaint->refund_amount : 0.0;
    }

    /**
     * Records the cash the courier paid the client at pick-up (out of the
     * platform's cash he holds) and reverses the sale. Runs in the caller's
     * transaction; the return then only waits for the shop's inspection.
     */
    private function payCashAtPickup(Complaint $c, ?User $actor, string $role, ?string $courier): Complaint
    {
        $amount = (float) $c->refund_amount;
        $c->update([
            'refund_method'    => 'cash',
            'refund_reference' => 'CASH' . ($courier ? ' · ' . \Illuminate\Support\Str::limit($courier, 60, '') : ''),
            'refunded_at'      => now(),
            'refunded_by'      => $actor?->id,
        ]);
        $this->applyFinance($c, $actor);
        $c->refresh();
        $this->event($c, 'refund_issued', $actor, $role, null, [
            'method' => 'cash', 'reference' => $c->refund_reference, 'amount' => (float) $c->refund_amount, 'paid_by' => $courier,
        ]);
        return $c;
    }

    /** The courier dropped the parcel at the shop: the seller (or admin) must inspect it. */
    public function deliveredToSeller(Complaint $complaint, ?User $courier): void
    {
        $this->event($complaint, 'delivered_to_seller', $courier, 'delivery', null);
        $this->seller($complaint, 'delivered_to_seller');
        $this->admin($complaint, 'delivered_to_seller');
    }

    // ── Reception (stock) ───────────────────────────────────────────────────

    /**
     * Item(s) back and inspected. Only resaleable units go back to stock — on
     * the exact variant — once per returned line (restocked_at).
     *
     * @param array<int,string> $conditions order_item_id => resaleable|damaged
     */
    public function receive(Complaint $complaint, User $actor, string $role, array $conditions, ?string $note): Complaint
    {
        if ($complaint->isExchange()) {
            throw new ReturnException('Legacy exchange requests can only be closed.');
        }

        $restockedLines = [];
        $c = DB::transaction(function () use ($complaint, $actor, $role, $conditions, $note, &$restockedLines) {
            $items = ComplaintItem::where('complaint_id', $complaint->id)->lockForUpdate()->get();
            foreach ($items as $item) {
                if (!in_array($conditions[$item->order_item_id] ?? null, [Complaint::CONDITION_RESALEABLE, Complaint::CONDITION_DAMAGED], true)) {
                    throw new ReturnException(__('messages.complaint.condition_required'));
                }
            }

            $c = $this->move($complaint, Complaint::STATUS_RETURNED_TO_SELLER, $actor, $role, $note, [
                'received_at'    => now(),
                'received_by'    => $actor->id,
                'reception_note' => $note,
            ], ['conditions' => $conditions], false);

            $restocked = 0;
            foreach ($items as $item) {
                $condition = $conditions[$item->order_item_id];
                $item->condition = $condition;
                if ($condition === Complaint::CONDITION_RESALEABLE && !$item->restocked_at) {
                    $line = DB::table('order_items')->where('id', $item->order_item_id)->first(['product_id', 'variant_id']);
                    if ($line) {
                        $line->variant_id && DB::table('product_variants')->where('id', $line->variant_id)->exists()
                            ? DB::table('product_variants')->where('id', $line->variant_id)->increment('stock', $item->quantity)
                            : DB::table('products')->where('id', $line->product_id)->increment('stock', $item->quantity);
                        $restockedLines[] = $line;
                        $item->restocked_quantity = $item->quantity;
                        $item->restocked_at       = now();
                        $restocked += $item->quantity;
                    }
                }
                $item->save();
            }

            Log::info("[Return] {$c->reference} received: {$restocked} unit(s) restocked.");

            // Already paid back in cash by the courier: nothing left to do
            if ($c->refunded_at) {
                $c = $this->move($c, Complaint::STATUS_REFUNDED, $actor, $role, null, ['resolved_at' => now()], null, false);
            }
            return $c;
        });

        // Product totals + stock-alert flags follow the restock (after the commit)
        foreach ($restockedLines as $line) {
            app(\App\Services\StockAlertService::class)->restocked((int) $line->product_id, $line->variant_id ? (int) $line->variant_id : null);
        }

        $this->buyer($c, 'returned');
        if (!$c->refunded_at) {
            $this->admin($c, 'returned_to_seller');
        }
        return $c;
    }

    // ── Refund (money + finance + order status) ─────────────────────────────

    /** Refund methods offered for the order's payment method (first = default). */
    public function refundMethodsFor(Order $order): array
    {
        return match ($order->payment_method ?? 'cod') {
            'card'   => ['original', 'wallet'],
            'wallet' => ['wallet'],
            'd17'    => ['d17', 'wallet', 'bank_transfer'],
            default  => ['cash'],   // paid back by the courier at pick-up
        };
    }

    public function refund(Complaint $complaint, User $admin, string $method, ?string $reference, ?string $note): Complaint
    {
        $order = $complaint->order()->firstOrFail();

        if ($complaint->refunded_at || $method === 'cash') {
            throw new ReturnException('Cash on delivery returns are paid back in cash by the courier at pick-up.');
        }
        if (!in_array($method, $this->refundMethodsFor($order), true)) {
            throw new ReturnException("Refund method \"{$method}\" is not available for a {$order->payment_method} order.");
        }
        if (in_array($method, ['bank_transfer', 'd17'], true) && !filled($reference)) {
            throw new ReturnException(__('messages.complaint.reference_required'));
        }
        if ($method === 'original' && !filled($reference)) {
            $reference = $this->stripeRefund($order, (float) $complaint->refund_amount);
        }

        $c = DB::transaction(function () use ($complaint, $admin, $method, $reference, $note, $order) {
            $c = $this->move($complaint, Complaint::STATUS_REFUNDED, $admin, 'admin', $note, [
                'refund_method'    => $method,
                'refunded_at'      => now(),
                'refunded_by'      => $admin->id,
            ], null, false);

            $this->applyFinance($c, $admin);

            $amount = (float) $c->fresh()->refund_amount;
            if ($method === 'wallet' && $amount > 0) {
                $tx = $this->wallet->creditReturnRefund($c->user, $order, $amount, $c->reference);
                $reference = $reference ?: 'WALLET-TX-' . $tx->id;
            }

            $c->update(['refund_reference' => $reference]);
            $this->event($c, 'refund_issued', $admin, 'admin', null, [
                'method' => $method, 'reference' => $reference, 'amount' => $amount,
            ]);
            return $c->fresh();
        });

        $this->buyer($c, 'refunded');
        $this->seller($c, 'refunded');
        return $c;
    }

    /**
     * Reverse the returned units of the sale, once (finance_applied_at):
     * order lines, sub-order revenue / commission / payout, order totals and
     * statuses, flash-sale quota; seller adjustment when already paid out.
     */
    public function applyFinance(Complaint $complaint, ?User $admin): void
    {
        $complaint = Complaint::whereKey($complaint->id)->lockForUpdate()->first();
        if ($complaint->finance_applied_at) {
            return;
        }

        $items = ComplaintItem::where('complaint_id', $complaint->id)->get();
        $lineIds = $items->pluck('order_item_id')->all();
        $lines = OrderItem::whereIn('id', $lineIds)->lockForUpdate()->get()->keyBy('id');

        $rev = ['gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'commission' => 0.0, 'seller' => 0.0];
        $fullyReturned = [];
        $bySellerOrder = [];

        foreach ($items as $item) {
            $line = $lines[$item->order_item_id] ?? null;
            if (!$line) continue;
            $qty = min((int) $item->quantity, (int) $line->quantity);
            if ($qty <= 0) continue;

            $share = $this->share($line, $qty);
            $item->update($share);

            $left = (int) $line->quantity - $qty;
            DB::table('order_items')->where('id', $line->id)->update([
                'quantity'          => $left,
                'returned_quantity' => (int) $line->returned_quantity + $qty,
                'returned_amount'   => round((float) $line->returned_amount + $share['net_amount'], 3),
                'returned_at'       => now(),
                'total'             => round((float) $line->getRawOriginal('total') - $share['gross_amount'], 3),
                'discount_amount'   => round((float) $line->discount_amount - $share['discount_amount'], 3),
                'net_total'         => $line->net_total !== null ? round((float) $line->net_total - $share['net_amount'], 3) : null,
                'commission_amount' => round((float) $line->commission_amount - $share['commission_amount'], 3),
                'seller_amount'     => round((float) $line->seller_amount - $share['seller_amount'], 3),
                // a fully returned line must never be given back to stock by a later cancel
                'stock_restored_at'     => $left === 0 ? ($line->stock_restored_at ?? now()) : $line->stock_restored_at,
                'stock_restored_reason' => $left === 0 ? ($line->stock_restored_reason ?? 'returned') : $line->stock_restored_reason,
                'updated_at'        => now(),
            ]);
            if ($left === 0) $fullyReturned[] = $line->id;

            foreach (['gross' => 'gross_amount', 'discount' => 'discount_amount', 'net' => 'net_amount', 'commission' => 'commission_amount', 'seller' => 'seller_amount'] as $k => $col) {
                $rev[$k] += $share[$col];
                $bySellerOrder[$line->seller_order_id ?? 0][$k] = ($bySellerOrder[$line->seller_order_id ?? 0][$k] ?? 0) + $share[$col];
            }
        }

        if ($fullyReturned) {
            app(PromotionService::class)->releaseForOrderItems($fullyReturned);
        }

        foreach ($bySellerOrder as $soId => $sum) {
            if ($soId) $this->reverseSellerOrder((int) $soId, $sum, $complaint, $admin);
        }

        // Return shipping charged to the seller when the item was wrong / defective
        $fee = (float) $complaint->return_shipping_fee;
        if ($complaint->shipping_payer === 'seller' && $fee > 0 && $complaint->seller_id) {
            SellerAdjustment::create([
                'seller_id'       => $complaint->seller_id,
                'seller_order_id' => array_key_first(array_filter($bySellerOrder, fn($k) => $k, ARRAY_FILTER_USE_KEY)) ?: null,
                'complaint_id'    => $complaint->id,
                'type'            => SellerAdjustment::TYPE_RETURN_SHIPPING,
                'amount'          => -round($fee, 3),
                'description'     => "Return shipping {$complaint->reference} (" . $complaint->getTypeLabel() . ')',
                'created_by'      => $admin?->id,
            ]);
        }

        $this->syncOrder((int) $complaint->order_id);

        $items_amount = round($rev['net'], 3);
        $complaint->update([
            'items_amount'       => $items_amount,
            'refund_amount'      => $this->refundFor($complaint, $items_amount),
            'finance_applied_at' => now(),
        ]);

        $this->event($complaint, 'finance_applied', $admin, 'system', null, array_map(fn($v) => round($v, 3), $rev));
    }

    private function reverseSellerOrder(int $soId, array $sum, Complaint $complaint, ?User $admin): void
    {
        $so = SellerOrder::whereKey($soId)->lockForUpdate()->first();
        if (!$so) return;

        $remaining = (int) DB::table('order_items')->where('seller_order_id', $soId)->sum('quantity');
        $full      = $remaining === 0;
        $paidOut   = $so->getAttribute('payout_status') === 'paid';
        $oldNet    = (float) $so->getAttribute('seller_net_amount');
        $oldCharge = (float) $so->getAttribute('seller_shipping_charge');

        $commission = max(0, round((float) $so->getAttribute('commission_amount') - $sum['commission'], 3));
        // The free-delivery contribution stays charged even when everything
        // comes back: the delivery was made and the agency was paid.
        $newNet = round($oldNet - $sum['seller'], 3);

        $update = [
            'subtotal'          => max(0, round((float) $so->subtotal - $sum['gross'], 3)),
            'discount_amount'   => max(0, round((float) $so->discount_amount - $sum['discount'], 3)),
            'commission_amount' => $commission,
            'platform_profit'   => round((float) $so->getAttribute('platform_profit') - $sum['commission'], 3),
            'return_status'     => $full ? 'full' : 'partial',
            'payment_status'    => 'refunded',
        ];
        if ($full) {
            $update['status'] = 'refunded';
        }

        if ($paidOut) {
            // History stays as paid; the difference is owed on the next settlement
            $debit = round($newNet - $oldNet, 3);
            if ($debit < 0) {
                SellerAdjustment::create([
                    'seller_id'       => $so->seller_id,
                    'seller_order_id' => $so->id,
                    'complaint_id'    => $complaint->id,
                    'type'            => SellerAdjustment::TYPE_RETURN_DEBIT,
                    'amount'          => $debit,
                    'description'     => "Return {$complaint->reference} refunded after payout (order {$complaint->order?->order_number})",
                    'created_by'      => $admin?->id,
                ]);
            }
        } elseif ($full) {
            // Nothing left to pay out; the contribution still owed (if any)
            // comes off the seller's next settlement.
            $update['seller_net_amount'] = 0;
            $update['payout_status']     = 'cancelled';
            if ($oldCharge > 0 && !$so->isPlatformParcel()) {
                SellerAdjustment::create([
                    'seller_id'       => $so->seller_id,
                    'seller_order_id' => $so->id,
                    'complaint_id'    => $complaint->id,
                    'type'            => SellerAdjustment::TYPE_FREE_DELIVERY,
                    'amount'          => -round($oldCharge, 3),
                    'description'     => "Free delivery contribution, order {$complaint->order?->order_number} fully returned ({$complaint->reference})",
                    'created_by'      => $admin?->id,
                ]);
            }
        } else {
            $update['seller_net_amount'] = $newNet;
        }

        $from = $so->status;
        $so->forceFill($update)->save();   // Eloquent: forecast / goal triggers run

        // Fully returned: the parcel's last step, in its status history
        if ($full && $from !== 'refunded') {
            \App\Services\Orders\ParcelStatus::record($so, $from, 'refunded', [
                'by' => $admin, 'source' => $admin ? 'admin' : 'system', 'note' => "Return {$complaint->reference}",
            ]);
        }

        if (!$paidOut && $so->getAttribute('settlement_batch_id')) {
            Settlements::recomputeDraft((int) $so->getAttribute('settlement_batch_id'));
        }
    }

    /** Order totals, status and return_status from its sub-orders. */
    public function syncOrder(int $orderId): void
    {
        $order = Order::find($orderId);
        if (!$order) return;

        $sos    = SellerOrder::where('order_id', $orderId)->get();
        $active = $sos->whereNotIn('status', SellerOrder::NOT_SHIPPED);

        // Delivery: per parcel (live parcels' own fees), or once per legacy order
        $shipping = $order->shipping_per_parcel
            ? $active->sum(fn($so) => (float) $so->getAttribute('delivery_fee'))
            : (float) ($order->shipping_fee ?? 0);
        $total = $active->sum(fn($so) => (float) $so->subtotal - (float) $so->discount_amount) + $shipping;
        $update = ['total_amount' => round($total, 3)];

        if ($active->isNotEmpty() && $active->every(fn($so) => $so->status === 'refunded')) {
            $update['status']        = 'refunded';
            $update['return_status'] = 'full';
        } elseif ($sos->contains(fn($so) => $so->return_status !== null)) {
            $update['return_status'] = 'partial';
        }

        $order->forceFill($update)->save();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Money of $qty units of a line, as paid (proportional share of the line's live figures). */
    public function share(OrderItem $line, int $qty): array
    {
        $current = max(1, (int) $line->quantity);
        $ratio   = min(1, $qty / $current);
        $whole   = $qty >= $current;
        $part    = fn($v) => $whole ? round((float) $v, 3) : round((float) $v * $ratio, 3);

        $gross    = $part($line->getRawOriginal('total') ?: (float) $line->unit_price * $current);
        $discount = $part($line->discount_amount ?? 0);
        $net      = $line->net_total !== null ? $part($line->net_total) : round($gross - $discount, 3);

        return [
            'unit_price'        => round((float) $line->unit_price, 3),
            'gross_amount'      => $gross,
            'discount_amount'   => $discount,
            'net_amount'        => $net,
            'commission_amount' => $part($line->commission_amount ?? 0),
            'seller_amount'     => $part($line->seller_amount ?? 0),
        ];
    }

    /** Admin setting (Delivery & Fees), frozen on the return when it is created. */
    public function returnShippingFee(): float
    {
        return \App\Support\Millimes::toFloat(app(\App\Services\Delivery\DeliverySettings::class)->returnShippingFee());
    }

    private function refundFor(Complaint $complaint, float $itemsAmount): float
    {
        $fee = $complaint->shipping_payer === 'client' ? (float) $complaint->return_shipping_fee : 0.0;
        return max(0, round($itemsAmount - $fee, 3));
    }

    /**
     * Locked transition + audit event. $notify=false: the caller runs it inside
     * its own transaction.
     */
    private function move(Complaint $complaint, string $to, ?User $actor, string $role, ?string $note, array $extra = [], ?array $meta = null, bool $own = true): Complaint
    {
        $run = function () use ($complaint, $to, $actor, $role, $note, $extra, $meta) {
            $fresh = Complaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            if (!$fresh->canTransitionTo($to)) {
                throw new ReturnException(__('messages.complaint.invalid_transition', [
                    'from' => __("statuses.return.{$fresh->status}"), 'to' => __("statuses.return.{$to}"),
                ]));
            }
            $fresh->update(['status' => $to] + $extra);
            $this->event($fresh, $to, $actor, $role, $note, $meta);
            return $fresh->fresh();
        };

        return $own ? DB::transaction($run) : $run();
    }

    public function event(Complaint $complaint, string $status, ?User $actor, string $role, ?string $note, ?array $meta = null): void
    {
        ComplaintEvent::create([
            'complaint_id' => $complaint->id,
            'status'       => $status,
            'actor_id'     => $actor?->id,
            'actor_role'   => $role,
            'note'         => $note,
            'meta'         => $meta,
            'created_at'   => now(),
        ]);
    }

    private function stripeRefund(Order $order, float $amount): string
    {
        $intent = $order->getAttribute('stripe_payment_intent_id');
        if (!$intent || !config('services.stripe.secret')) {
            throw new ReturnException('No Stripe payment to refund automatically — refund it in Stripe and enter the refund reference.');
        }
        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $refund = \Stripe\Refund::create([
                'payment_intent' => $intent,
                'amount'         => (int) round($amount * 1000), // TND has 3 decimals (millimes)
            ]);
            return $refund->id;
        } catch (\Throwable $e) {
            Log::error("[Return] Stripe refund failed for order {$order->order_number}: {$e->getMessage()}");
            throw new ReturnException('Stripe refund failed: ' . $e->getMessage());
        }
    }

    private function admins(): Collection
    {
        return User::where('role', 'admin')->where('is_active', true)->get();
    }

    private function buyer(Complaint $complaint, string $event): void
    {
        $complaint->loadMissing('user');
        app(BuyerNotifier::class)->send($complaint->user, new ComplaintNotification($complaint->fresh(), $event));
    }

    private function admin(Complaint $complaint, string $event): void
    {
        try {
            Notification::send($this->admins(), new ReturnAdminNotification($complaint->fresh(), $event));
        } catch (\Throwable $e) {
            Log::error("[Return] admin notification {$event} failed: " . $e->getMessage());
        }
    }

    private function seller(Complaint $complaint, string $event): void
    {
        try {
            if ($complaint->seller_id && ($seller = User::find($complaint->seller_id))) {
                $seller->notify(new ReturnSellerNotification($complaint->fresh(), $event));
            }
        } catch (\Throwable $e) {
            Log::error("[Return] seller notification {$event} failed: " . $e->getMessage());
        }
    }
}
