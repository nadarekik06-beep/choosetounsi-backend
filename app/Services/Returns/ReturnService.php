<?php

namespace App\Services\Returns;

use App\Support\Millimes;
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
 *   - orders with delivery included in the prices (PricingService): the parcel
 *     is recomputed as if the returned units had never been ordered, refund =
 *     what the client paid − the recomputed total. A full return gives back
 *     everything paid, parcel fee included; the seller's payout and commission
 *     still move by the base price only. Seller at fault on a full return: the
 *     seller is charged that parcel fee (and the return shipping) on the next
 *     settlements; changed mind: only the return shipping comes off the refund;
 *   - cash on delivery → the courier checks the item against the proof
 *     photos and pays the client back in cash at pick-up, out of the cash
 *     he holds for the platform (refund_method 'cash'); the sale is reversed
 *     then, the shop still inspects it at reception (restock);
 *     paid online → after reception: card → original method (Stripe) or
 *     wallet; wallet → wallet; D17 → D17 / wallet / transfer.
 *   - on refund the sale is reversed on the order line and the sub-order
 *     (revenue, commission, payout); a sub-order already paid out gets a
 *     negative adjustment on the next settlement instead.
 *
 * After-sale fee (one per complaint = one Intigo parcel, at the largest
 * parcel size of its lines, frozen at request):
 *   - return: the size's after-sale return fee — client's choice: deducted
 *     from the refund (never below 0, flagged when it eats it all); seller at
 *     fault: off the seller's next settlement;
 *   - exchange: the size's exchange fee — client's choice: cash the courier
 *     collects (the Intigo exchange parcel's price); seller at fault: 0 for the
 *     client, off the seller's next settlement.
 *   An exchange is the same sale with another variant: no new commission, no
 *   new payout. What Intigo bills and what we recover go to complaint_costs.
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
    public function create(?User $client, Order $order, array $quantities, array $data, array $images): Collection
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
            if ($complaint->fee_exceeds_refund) {
                $this->admin($complaint, 'fee_exceeds_refund');
            }
        }

        return $complaints;
    }

    private function createRows(?User $client, Order $order, array $quantities, array $data, array $paths, Collection $lines): Collection
    {
        return DB::transaction(function () use ($client, $order, $quantities, $data, $paths, $lines) {
            $allLines = OrderItem::where('order_id', $order->id)->lockForUpdate()->get();
            $reserved = $this->reservedQuantities($order->id);

            $groups = $lines->groupBy(fn($l) => $l->seller_order_id ?? 0);
            $created = collect();

            $exchange = ($data['resolution_type'] ?? null) === Complaint::RESOLUTION_EXCHANGE;
            $replacements = $exchange ? $this->replacementsFor($lines, $quantities, (array) ($data['replacements'] ?? [])) : [];

            $this->guardLegacyPacks($allLines, $quantities, $reserved);

            foreach ($groups as $soId => $group) {
                $sellerId = $group->first()->sellerOrder?->seller_id
                    ?? DB::table('products')->where('id', $group->first()->product_id)->value('seller_id');

                // One parcel = one Intigo bill, at the largest size it holds
                $size = \App\Support\ParcelSize::largest(
                    DB::table('products')->whereIn('id', $group->pluck('product_id'))->pluck('parcel_size')->all() ?: ['S']
                );
                $settings = app(\App\Services\Delivery\DeliverySettings::class);

                $complaint = Complaint::create([
                    // Guest (no account): the order's account if it was added to one since, else none
                    'user_id'         => $client?->id ?? $order->user_id,
                    'order_id'        => $order->id,
                    'seller_id'       => $sellerId,
                    'order_item_ids'  => $group->pluck('id')->map(fn($v) => (int) $v)->values()->all(),
                    'complaint_type'  => $data['complaint_type'],
                    'resolution_type' => $exchange ? Complaint::RESOLUTION_EXCHANGE : Complaint::RESOLUTION_RETURN_REFUND,
                    'other_reason'    => $data['other_reason'] ?? null,
                    'description'     => $data['description'],
                    'image_path'      => $paths[0] ?? null,
                    'image_paths'     => $paths,
                    'status'          => Complaint::STATUS_REQUESTED,
                    'shipping_payer'  => Complaint::payerFor($data['complaint_type']),
                    'fee_size'        => $size,
                    'return_shipping_fee' => \App\Support\Millimes::toFloat($settings->afterSaleReturnFee($size)),
                    'exchange_fee'        => \App\Support\Millimes::toFloat($settings->exchangeFee($size)),
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
                    ComplaintItem::create(['complaint_id' => $complaint->id, 'order_item_id' => $line->id, 'quantity' => $qty]
                        + $share + ($replacements[$line->id] ?? []));
                }

                // Whole shop's part of the order: every unit of every line of that sub-order
                $shopLines  = $allLines->where('seller_order_id', $soId ?: null);
                $isFull     = $shopLines->every(fn($l) => (int) ($quantities[$l->id] ?? 0) + (int) ($reserved[$l->id] ?? 0) >= (int) $l->quantity);
                $fresh      = $complaint->fresh();
                // Delivery included in the prices: the parcel fee the returned units free up is refunded too
                $amount += $this->deliveryRefundPreview((int) $soId, $group, $quantities);

                $complaint->update([
                    'return_scope'       => $isFull ? 'full' : 'partial',
                    'items_amount'       => round($amount, 3),
                    'refund_amount'      => $this->refundFor($fresh, $amount),
                    'fee_exceeds_refund' => $this->feeExceedsRefund($fresh, $amount),
                ]);

                $this->event($complaint, Complaint::STATUS_REQUESTED, $client, 'client', $data['description']);
                $created->push($complaint->fresh());
            }

            return $created;
        });
    }

    /**
     * What a return of these units would give back, before it is filed (the
     * return form shows it; the storefront never computes a refund itself).
     * Same rules as create(): one return per shop, its fee at the largest size,
     * paid by the client only when the return is their choice.
     *
     * @param  array<int,int> $quantities order_item_id => units to return
     * @return array{items_amount: float, client_fee: float, refund_amount: float, shipping_payer: string}
     */
    public function previewRefund(Order $order, array $quantities, string $complaintType): array
    {
        $lines = OrderItem::where('order_id', $order->id)->whereIn('id', array_keys($quantities))->get();
        $payer = Complaint::payerFor($complaintType);
        $settings = app(\App\Services\Delivery\DeliverySettings::class);

        $items = $fees = $refund = 0;
        foreach ($lines->groupBy(fn ($l) => $l->seller_order_id ?? 0) as $soId => $group) {
            $amount = 0;
            foreach ($group as $line) {
                $qty = min((int) $quantities[$line->id], (int) $line->quantity);
                if ($qty > 0) {
                    $amount += Millimes::of($this->share($line, $qty)['net_amount']);
                }
            }
            $amount += Millimes::of($this->deliveryRefundPreview((int) $soId, $group, $quantities));
            $size = \App\Support\ParcelSize::largest(
                DB::table('products')->whereIn('id', $group->pluck('product_id'))->pluck('parcel_size')->all() ?: ['S']
            );
            $fee = $payer === 'client' ? $settings->afterSaleReturnFee($size) : 0;
            $items  += $amount;
            $fees   += $fee;
            $refund += max(0, $amount - $fee);
        }

        return [
            'items_amount'   => Millimes::toFloat($items),
            'client_fee'     => Millimes::toFloat($fees),
            'refund_amount'  => Millimes::toFloat($refund),
            'shipping_payer' => $payer,
        ];
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

    public function escalate(Complaint $complaint, ?User $client, ?string $note): Complaint
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

    public function clientCancel(Complaint $complaint, ?User $client): Complaint
    {
        if ($complaint->status !== Complaint::STATUS_REQUESTED) {
            throw new ReturnException(__('messages.complaint.cannot_cancel'));
        }
        $c = $this->move($complaint, Complaint::STATUS_CANCELLED, $client, 'client', null, ['resolved_at' => now()]);
        $this->buyer($c, 'cancelled');
        return $c;
    }

    /**
     * Admin approval (also overrides a seller refusal). The admin may switch
     * who pays the after-sale parcel first ("other" is sometimes the seller's
     * fault): logged in the history, refund / cash to collect recomputed.
     *
     * Return: opens the pick-up. Exchange: holds the replacement stock, then
     * books the Intigo exchange parcel (or leaves the manual booking step).
     */
    public function adminApprove(Complaint $complaint, User $admin, ?string $note, ?string $shippingPayer = null): Complaint
    {
        if ($complaint->isLegacyExchange()) {
            throw new ReturnException('Legacy exchange requests can only be closed.');
        }
        $extra = [
            'admin_decided_by' => $admin->id,
            'admin_decided_at' => now(),
            'admin_note'       => $note,
            'resolved_at'      => now(),
        ];

        $sold = [];
        $c = DB::transaction(function () use ($complaint, $admin, $note, $extra, $shippingPayer, &$sold) {
            $c = $this->move($complaint, Complaint::STATUS_ADMIN_APPROVED, $admin, 'admin', $note, $extra, null, false);
            if ($shippingPayer && $shippingPayer !== $c->shipping_payer) {
                $this->event($c, 'payer_overridden', $admin, 'admin', $note, [
                    'from' => $c->shipping_payer, 'to' => $shippingPayer, 'fee' => $c->caseFee(),
                ]);
                $c->update(['shipping_payer' => $shippingPayer]);
            }
            $c->update([
                'refund_amount'      => $this->refundFor($c, (float) $c->items_amount),
                'fee_exceeds_refund' => $this->feeExceedsRefund($c, (float) $c->items_amount),
            ]);
            if ($c->isExchange()) {
                $sold = $this->reserveReplacements($c);
            }
            return $c->fresh();
        });

        if ($c->isExchange()) {
            if ($sold) app(\App\Services\StockAlertService::class)->recordSales($sold);
            $this->buyer($c, 'approved');
            $this->seller($c, 'admin_approved');
            app(\App\Services\Intigo\ExchangeSender::class)->book($c, $admin);
            return $c->fresh();
        }

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
        $released = [];
        $c = DB::transaction(function () use ($complaint, $admin, $reason, &$released) {
            $c = $this->move($complaint, Complaint::STATUS_CANCELLED, $admin, 'admin', $reason, [
                'admin_note'  => $reason,
                'resolved_at' => now(),
            ], null, false);
            $released = $this->releaseReplacements($c);
            return $c;
        });
        $this->restocked($released);
        RefundTaskSync::cancel($c);
        $this->buyer($c, 'cancelled');
        $this->seller($c, 'cancelled');
        return $c;
    }

    /** Legacy exchange records: nothing more to do than close them. */
    public function closeLegacyExchange(Complaint $complaint, User $admin, ?string $note): Complaint
    {
        if (!$complaint->isLegacyExchange()) {
            throw new ReturnException('Only legacy exchange requests can be closed.');
        }
        return $this->move($complaint, Complaint::STATUS_CLOSED, $admin, 'admin', $note, ['resolved_at' => now()]);
    }

    // ── Exchange ────────────────────────────────────────────────────────────

    /**
     * The exchange parcel exists at Intigo (booked by the API, or by hand and
     * its nid pasted): replacement on its way, cash to collect = client fee.
     */
    public function exchangeShipped(Complaint $complaint, ?User $actor, string $role, string $nid, string $source): Complaint
    {
        $nid = trim($nid);
        if ($nid === '') {
            throw new ReturnException('The Intigo parcel id (nid) is required.');
        }
        if (Complaint::where('intigo_exchange_nid', $nid)->where('id', '!=', $complaint->id)->exists()
            || \App\Models\SellerOrder::where('intigo_nid', $nid)->exists()) {
            throw new ReturnException("Intigo parcel {$nid} is already linked to another order or complaint.");
        }

        $previous = $complaint->intigo_exchange_nid;
        $c = $this->move($complaint, Complaint::STATUS_EXCHANGE_IN_TRANSIT, $actor, $role, null, [
            'intigo_exchange_nid'          => $nid,
            'exchange_sync_status'         => $source === 'api' ? Complaint::EXCHANGE_SENT : Complaint::EXCHANGE_MANUAL,
            'exchange_sync_error'          => null,
            'intigo_exchange_status_code'  => null,
            'intigo_exchange_status_label' => null,
            'intigo_exchange_status_at'    => null,
            'exchange_shipped_at'          => now(),
            'exchange_failed_at'           => null,
        ], ['nid' => $nid, 'source' => $source, 'previous_nid' => $previous, 'cod' => $complaint->clientFee()]);

        $this->buyer($c, 'exchange_in_transit');
        return $c;
    }

    /**
     * Replacement delivered, cash collected, old item taken back by the courier.
     * Books the exchange cost and the seller's share when at fault.
     */
    public function markExchanged(Complaint $complaint, ?User $actor, string $role, ?string $note = null): Complaint
    {
        $c = DB::transaction(function () use ($complaint, $actor, $role, $note) {
            $c = $this->move($complaint, Complaint::STATUS_EXCHANGED, $actor, $role, $note, ['exchanged_at' => now()], null, false);
            $this->bookCost($c, \App\Models\ComplaintCost::KIND_EXCHANGE, (float) $c->exchange_fee, $c->clientFee(), $c->sellerFee(), $c->intigo_exchange_nid);
            if ($c->sellerFee() > 0 && $c->seller_id
                && !SellerAdjustment::where('complaint_id', $c->id)->where('type', SellerAdjustment::TYPE_EXCHANGE_SHIPPING)->exists()) {
                SellerAdjustment::create([
                    'seller_id'       => $c->seller_id,
                    'seller_order_id' => $this->sellerOrderId($c),
                    'complaint_id'    => $c->id,
                    'type'            => SellerAdjustment::TYPE_EXCHANGE_SHIPPING,
                    'amount'          => -round($c->sellerFee(), 3),
                    'description'     => "Exchange parcel {$c->reference} (" . $c->getTypeLabel() . ')',
                    'created_by'      => $actor?->id,
                ]);
            }
            return $c->fresh();
        });

        $this->buyer($c, 'exchanged');
        $this->seller($c, 'exchanged');
        return $c;
    }

    /** Refused at the door: the replacement goes back to the seller, the admin decides. */
    public function markExchangeFailed(Complaint $complaint, ?User $actor, string $role, ?string $note = null): Complaint
    {
        $c = DB::transaction(function () use ($complaint, $actor, $role, $note) {
            $c = $this->move($complaint, Complaint::STATUS_EXCHANGE_FAILED, $actor, $role, $note, ['exchange_failed_at' => now()], null, false);
            // Intigo bills the refused parcel's return; nothing recovered
            $refused = \App\Support\Millimes::toFloat(app(\App\Services\Delivery\DeliverySettings::class)->refusedFee($c->fee_size));
            $this->bookCost($c, \App\Models\ComplaintCost::KIND_EXCHANGE_FAILED, $refused, 0, 0, $c->intigo_exchange_nid);
            return $c->fresh();
        });

        $this->buyer($c, 'exchange_failed');
        $this->seller($c, 'exchange_failed');
        $this->admin($c, 'exchange_failed');
        return $c;
    }

    /**
     * Admin decision on a failed exchange:
     *   retry   book a new exchange parcel (replacement stays held)
     *   return  switch to a return: replacement released, the pick-up follows,
     *           the return fee replaces the exchange fee
     *   cancel  replacement released, case closed
     */
    public function decideFailedExchange(Complaint $complaint, User $admin, string $action, ?string $note): Complaint
    {
        if ($complaint->status !== Complaint::STATUS_EXCHANGE_FAILED) {
            throw new ReturnException('Only a failed exchange waits for this decision.');
        }

        if ($action === 'retry') {
            $this->event($complaint, 'exchange_retry', $admin, 'admin', $note);
            app(\App\Services\Intigo\ExchangeSender::class)->book($complaint->fresh(), $admin);
            return $complaint->fresh();
        }
        if ($action === 'cancel') {
            return $this->adminCancel($complaint, $admin, $note ?: 'Exchange failed: cancelled by ChooseTounsi.');
        }
        if ($action !== 'return') {
            throw new ReturnException("Unknown decision \"{$action}\".");
        }

        $released = [];
        $c = DB::transaction(function () use ($complaint, $admin, $note, &$released) {
            $released = $this->releaseReplacements($complaint);
            Complaint::whereKey($complaint->id)->update(['resolution_type' => Complaint::RESOLUTION_RETURN_REFUND]);
            $c = $this->move($complaint->fresh(), Complaint::STATUS_ADMIN_APPROVED, $admin, 'admin', $note, [
                'admin_decided_by' => $admin->id,
                'admin_decided_at' => now(),
            ], ['converted_from' => 'exchange'], false);
            $c->update([
                'refund_amount'      => $this->refundFor($c, (float) $c->items_amount),
                'fee_exceeds_refund' => $this->feeExceedsRefund($c, (float) $c->items_amount),
            ]);
            return $c->fresh();
        });
        $this->restocked($released);

        ComplaintApproved::dispatch($c);
        $this->buyer($c, 'approved');
        $this->seller($c, 'admin_approved');
        return $c;
    }

    /**
     * Client's choice of replacement per line, checked: same product, active,
     * in stock (summed over lines), same price as what they had. Products
     * without variants: the same product again.
     *
     * @return array<int, array{replacement_variant_id: ?int, replacement_label: string}> order_item_id => columns
     */
    public function replacementsFor(Collection $lines, array $quantities, array $choices): array
    {
        $out = $need = [];
        foreach ($lines as $line) {
            $product = \App\Models\Product::with('variants.attributeOptions.attribute')->find($line->product_id);
            if (!$product || !$product->is_active) {
                throw new ReturnException(__('messages.complaint.exchange_unavailable', ['item' => $line->product_name]));
            }
            $variants = $product->variants->where('is_active', true);
            $chosen   = $choices[$line->id] ?? null;

            if ($variants->isEmpty()) {
                if ($chosen !== null && (int) $chosen !== 0) {
                    throw new ReturnException(__('messages.complaint.exchange_same_product', ['item' => $line->product_name]));
                }
                $key = 'p' . $product->id;
                $need[$key] = ($need[$key] ?? 0) + (int) $quantities[$line->id];
                if ($need[$key] > (int) $product->stock) {
                    throw new ReturnException(__('messages.complaint.exchange_out_of_stock', ['item' => $line->product_name]));
                }
                $out[$line->id] = ['replacement_variant_id' => null, 'replacement_label' => $line->product_name];
                continue;
            }

            $variant = $chosen ? $variants->firstWhere('id', (int) $chosen) : null;
            if (!$variant) {
                throw new ReturnException(__('messages.complaint.exchange_choose_variant', ['item' => $line->product_name]));
            }
            $original = $line->variant_id ? $product->variants->firstWhere('id', (int) $line->variant_id) : null;
            $before   = $original ? $original->effective_price : (float) $line->unit_price;
            if (abs($variant->effective_price - $before) > 0.0005) {
                throw new ReturnException(__('messages.complaint.exchange_price_differs', ['item' => $line->product_name]));
            }
            $key = 'v' . $variant->id;
            $need[$key] = ($need[$key] ?? 0) + (int) $quantities[$line->id];
            if ($need[$key] > (int) $variant->stock) {
                throw new ReturnException(__('messages.complaint.exchange_out_of_stock', ['item' => $line->product_name . ($variant->label ? " — {$variant->label}" : '')]));
            }
            $out[$line->id] = ['replacement_variant_id' => $variant->id, 'replacement_label' => trim($line->product_name . ($variant->label ? " — {$variant->label}" : ''))];
        }
        return $out;
    }

    /**
     * Takes the replacement units off the shelf (once). Runs in the caller's
     * transaction; returns the lines for StockAlertService::recordSales().
     */
    private function reserveReplacements(Complaint $c): array
    {
        $sold  = [];
        $items = ComplaintItem::where('complaint_id', $c->id)->lockForUpdate()->get();
        $stock = app(\App\Services\Orders\OrderStock::class);
        foreach ($items as $item) {
            if ($item->replacement_reserved_at && !$item->replacement_released_at) continue;
            $productId = (int) DB::table('order_items')->where('id', $item->order_item_id)->value('product_id');
            try {
                $stock->reserve($item->replacement_variant_id ? (int) $item->replacement_variant_id : null, $productId, (int) $item->quantity, (string) $item->replacement_label);
            } catch (\App\Exceptions\InsufficientStock $e) {
                throw new ReturnException(__('messages.complaint.exchange_out_of_stock', ['item' => $item->replacement_label]));
            }
            $item->update(['replacement_reserved_at' => now(), 'replacement_released_at' => null]);
            $sold[] = ['product_id' => $productId, 'variant_id' => $item->replacement_variant_id ? (int) $item->replacement_variant_id : null];
        }
        return $sold;
    }

    /**
     * Gives held replacement units back (exchange cancelled / switched to a
     * return). Runs in the caller's transaction; returns the lines to resync.
     *
     * @return array<int, array{0:int, 1:?int}> [product_id, variant_id]
     */
    private function releaseReplacements(Complaint $c): array
    {
        $out = [];
        foreach (ComplaintItem::where('complaint_id', $c->id)->lockForUpdate()->get() as $item) {
            if (!$item->replacement_reserved_at || $item->replacement_released_at) continue;
            $productId = (int) DB::table('order_items')->where('id', $item->order_item_id)->value('product_id');
            $item->replacement_variant_id
                ? DB::table('product_variants')->where('id', $item->replacement_variant_id)->increment('stock', (int) $item->quantity)
                : DB::table('products')->where('id', $productId)->increment('stock', (int) $item->quantity);
            $item->update(['replacement_released_at' => now()]);
            $out[] = [$productId, $item->replacement_variant_id ? (int) $item->replacement_variant_id : null];
        }
        if ($out) {
            $this->event($c, 'replacement_released', null, 'system', null);
        }
        return $out;
    }

    private function restocked(array $lines): void
    {
        foreach ($lines as [$productId, $variantId]) {
            app(\App\Services\StockAlertService::class)->restocked($productId, $variantId);
        }
    }

    /** One cost row per (complaint, kind, Intigo parcel). */
    private function bookCost(Complaint $c, string $kind, float $cost, float $client, float $seller, ?string $nid): void
    {
        \App\Models\ComplaintCost::firstOrCreate(
            ['complaint_id' => $c->id, 'kind' => $kind, 'intigo_nid' => $nid],
            [
                'size'             => $c->fee_size,
                'intigo_cost'      => round($cost, 3),
                'recovered_client' => round($client, 3),
                'recovered_seller' => round($seller, 3),
                'payer'            => $c->shipping_payer,
                'booked_at'        => now(),
            ]
        );
    }

    /** The sub-order (= original parcel) the complaint's lines belong to. */
    public function sellerOrderId(Complaint $c): ?int
    {
        $id = DB::table('complaint_items as ci')->join('order_items as oi', 'oi.id', '=', 'ci.order_item_id')
            ->where('ci.complaint_id', $c->id)->whereNotNull('oi.seller_order_id')->value('oi.seller_order_id');
        return $id ? (int) $id : null;
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
        if ($complaint->isLegacyExchange()) {
            throw new ReturnException('Legacy exchange requests can only be closed.');
        }
        if ($complaint->isExchange()) {
            return $this->receiveOldItem($complaint, $actor, $role, $conditions, $note);
        }

        $restockedLines = [];
        $c = DB::transaction(function () use ($complaint, $actor, $role, $conditions, $note, &$restockedLines) {
            $items = ComplaintItem::where('complaint_id', $complaint->id)->lockForUpdate()->get();
            $this->checkConditions($items, $conditions);

            $c = $this->move($complaint, Complaint::STATUS_RETURNED_TO_SELLER, $actor, $role, $note, [
                'received_at'    => now(),
                'received_by'    => $actor->id,
                'reception_note' => $note,
            ], ['conditions' => $conditions], false);

            $restocked = $this->restockItems($items, $conditions, $restockedLines);

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

    /**
     * Exchange: the courier brought the old item back to the seller's pickup
     * address. The seller's confirmation closes the case (restock if resaleable).
     */
    private function receiveOldItem(Complaint $complaint, User $actor, string $role, array $conditions, ?string $note): Complaint
    {
        $restockedLines = [];
        $c = DB::transaction(function () use ($complaint, $actor, $role, $conditions, $note, &$restockedLines) {
            $items = ComplaintItem::where('complaint_id', $complaint->id)->lockForUpdate()->get();
            $this->checkConditions($items, $conditions);

            $c = $this->move($complaint, Complaint::STATUS_EXCHANGE_COMPLETED, $actor, $role, $note, [
                'received_at'          => now(),
                'received_by'          => $actor->id,
                'reception_note'       => $note,
                'old_item_received_at' => now(),
                'resolved_at'          => now(),
            ], ['conditions' => $conditions, 'old_item' => true], false);

            $restocked = $this->restockItems($items, $conditions, $restockedLines);
            Log::info("[Exchange] {$c->reference} old item received: {$restocked} unit(s) restocked.");
            return $c;
        });

        foreach ($restockedLines as $line) {
            app(\App\Services\StockAlertService::class)->restocked((int) $line->product_id, $line->variant_id ? (int) $line->variant_id : null);
        }
        $this->buyer($c, 'exchange_completed');
        return $c;
    }

    private function checkConditions(Collection $items, array $conditions): void
    {
        foreach ($items as $item) {
            if (!in_array($conditions[$item->order_item_id] ?? null, [Complaint::CONDITION_RESALEABLE, Complaint::CONDITION_DAMAGED], true)) {
                throw new ReturnException(__('messages.complaint.condition_required'));
            }
        }
    }

    /** Resaleable units back on their exact variant, once per line. Returns units restocked. */
    private function restockItems(Collection $items, array $conditions, array &$restockedLines): int
    {
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
        return $restocked;
    }

    // ── Refund (money + finance + order status) ─────────────────────────────

    /** Refund methods offered for the order's payment method (first = default). */
    public function refundMethodsFor(Order $order): array
    {
        $methods = match ($order->payment_method ?? 'cod') {
            'card'   => ['original', 'wallet'],
            'wallet' => ['wallet'],
            'd17'    => ['d17', 'wallet', 'bank_transfer'],
            default  => ['cash'],   // paid back by the courier at pick-up
        };
        // Guest order (no account): there is no wallet to credit
        return $order->user_id ? $methods : (array_values(array_diff($methods, ['wallet'])) ?: ['bank_transfer']);
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
            $funded = $this->fundedShare($line, $qty);

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
                'platform_funded_amount' => Millimes::toDecimal(Millimes::of($line->platform_funded_amount) - $funded),
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
            $bySellerOrder[$line->seller_order_id ?? 0]['funded_m'] = ($bySellerOrder[$line->seller_order_id ?? 0]['funded_m'] ?? 0) + $funded;
        }

        if ($fullyReturned) {
            app(PromotionService::class)->releaseForOrderItems($fullyReturned);
        }

        // Parcel fee given back with the items (orders with delivery included in the prices)
        $deliveryRefundM = 0;
        foreach ($bySellerOrder as $soId => $sum) {
            if ($soId) {
                $this->reverseSellerOrder((int) $soId, $sum, $complaint, $admin);
                $deliveryRefundM += $this->refundIncludedDelivery((int) $soId, $complaint, $admin);
            }
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

        $items_amount = round($rev['net'] + Millimes::toFloat($deliveryRefundM), 3);
        $complaint->update([
            'items_amount'       => $items_amount,
            'refund_amount'      => $this->refundFor($complaint, $items_amount),
            'fee_exceeds_refund' => $this->feeExceedsRefund($complaint, $items_amount),
            'finance_applied_at' => now(),
        ]);

        // What Intigo bills for the return parcel (a normal parcel of that size)
        // and what was recovered (refund deduction, capped by the refund; seller debit)
        if ($complaint->fee_size) {
            $settings = app(\App\Services\Delivery\DeliverySettings::class);
            $this->bookCost($complaint, \App\Models\ComplaintCost::KIND_RETURN,
                \App\Support\Millimes::toFloat($settings->agencyCost($complaint->fee_size)),
                min($complaint->clientFee(), $items_amount), $complaint->sellerFee(), $complaint->intigo_return_ref);
        }

        $this->event($complaint, 'finance_applied', $admin, 'system', null,
            array_map(fn($v) => round($v, 3), $rev) + ['delivery_refund' => Millimes::toFloat($deliveryRefundM)]);
    }

    /**
     * Delivery part of a refund, before it happens (the amount shown when the
     * return is requested): 0 for orders placed before delivery was included.
     */
    private function deliveryRefundPreview(int $soId, Collection $lines, array $quantities): float
    {
        if (!$soId) {
            return 0.0;
        }
        $minus = [];
        foreach ($lines as $line) {
            $minus[$line->id] = (int) ($quantities[$line->id] ?? 0);
        }
        $r = app(\App\Services\Pricing\PricingService::class)->recomputeParcel($soId, $minus);
        return $r ? Millimes::toFloat($r['fee_delta_m']) : 0.0;
    }

    /**
     * The parcel recomputed without the returned units (already reversed on its
     * lines): its included delivery, multi-item discount and client total follow.
     * Returns the parcel fee given back to the client, in millimes.
     *
     * Full return with the seller at fault: that fee is charged to the seller
     * (next settlements); otherwise the platform carries it.
     */
    private function refundIncludedDelivery(int $soId, Complaint $complaint, ?User $admin): int
    {
        $r = app(\App\Services\Pricing\PricingService::class)->recomputeParcel($soId, [], true);
        if (!$r) {
            return 0;   // placed before delivery was included: the delivery fee is never refunded
        }
        $deltaM = $r['fee_delta_m'];
        $so     = SellerOrder::find($soId);

        if ($deltaM > 0 && $r['units'] === 0 && $complaint->shipping_payer === 'seller'
            && $so && $so->seller_id && !$so->isPlatformParcel()
            && !SellerAdjustment::where('complaint_id', $complaint->id)->where('seller_order_id', $soId)
                ->where('type', SellerAdjustment::TYPE_RETURN_PARCEL_FEE)->exists()) {
            SellerAdjustment::create([
                'seller_id'       => $so->seller_id,
                'seller_order_id' => $soId,
                'complaint_id'    => $complaint->id,
                'type'            => SellerAdjustment::TYPE_RETURN_PARCEL_FEE,
                'amount'          => '-' . Millimes::toDecimal($deltaM),
                'description'     => "Delivery of order {$complaint->order?->order_number} refunded to the client, full return {$complaint->reference} (" . $complaint->getTypeLabel() . ')',
                'created_by'      => $admin?->id,
            ]);
        }
        if ($so && $so->getAttribute('settlement_batch_id') && $so->getAttribute('payout_status') !== 'paid') {
            Settlements::recomputeDraft((int) $so->getAttribute('settlement_batch_id'));
        }
        return $deltaM;
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

        // Platform-funded promotions can leave a negative commission: only clamp tiny rounding below 0
        $oldCommission = (float) $so->getAttribute('commission_amount');
        $commission    = round($oldCommission - $sum['commission'], 3);
        if ($oldCommission >= 0) {
            $commission = max(0, $commission);
        }
        // The free-delivery contribution stays charged even when everything
        // comes back: the delivery was made and the agency was paid.
        $newNet = round($oldNet - $sum['seller'], 3);

        $update = [
            'subtotal'          => max(0, round((float) $so->subtotal - $sum['gross'], 3)),
            'discount_amount'   => max(0, round((float) $so->discount_amount - $sum['discount'], 3)),
            'commission_amount' => $commission,
            'platform_profit'   => round((float) $so->getAttribute('platform_profit') - $sum['commission'], 3),
            'platform_funded_amount' => Millimes::toDecimal(max(0, Millimes::of($so->getAttribute('platform_funded_amount')) - ($sum['funded_m'] ?? 0))),
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

    /**
     * Money of $qty units of a line, as paid (proportional share of the line's
     * live figures). Integer millimes (AUDIT L-2): each figure rounded half-up
     * once; the seller amount is net − commission, so the share always balances.
     * The last units of a line take exactly what is left.
     *
     * Pack lines (AUDIT C-1) hold their own share of the pack price, so this is
     * exact for them too. Legacy pack lines are guarded by guardLegacyPacks().
     */
    public function share(OrderItem $line, int $qty): array
    {
        $current = max(1, (int) $line->quantity);
        $whole   = $qty >= $current;
        $part    = fn ($v) => $whole ? Millimes::of($v ?? 0) : Millimes::share(Millimes::of($v ?? 0), $qty, $current);

        $rawTotal = $line->getRawOriginal('total');
        $gross    = $part(Millimes::of($rawTotal) !== 0 ? $rawTotal : Millimes::toDecimal(Millimes::of($line->unit_price) * $current));
        $discount = $part($line->discount_amount);
        $net      = $line->net_total !== null ? $part($line->net_total) : $gross - $discount;
        $commission = $part($line->commission_amount);
        $seller   = $whole ? Millimes::of($line->seller_amount ?? 0) : $net - $commission;
        if ($whole) {
            $commission = Millimes::of($line->commission_amount ?? 0);
        }
        $f = fn (int $m) => Millimes::toFloat($m);

        return [
            'unit_price'        => round((float) $line->unit_price, 3),
            'gross_amount'      => $f($gross),
            'discount_amount'   => $f($discount),
            'net_amount'        => $f($net),
            'commission_amount' => $f($commission),
            'seller_amount'     => $f($seller),
        ];
    }

    /** Platform-funded discount (AUDIT M-8) carried by $qty units of a line, in millimes. */
    private function fundedShare(OrderItem $line, int $qty): int
    {
        $current = max(1, (int) $line->quantity);
        $funded  = Millimes::of($line->platform_funded_amount ?? 0);
        return $qty >= $current ? $funded : Millimes::share($funded, $qty, $current);
    }

    /**
     * Orders placed before pack money was spread over each line (pack_id null,
     * "(Bundle: …)" lines: all the money on the first line): a partial return
     * would refund the wrong amount, so such a pack can only be returned whole —
     * every line of it, every unit (AUDIT C-1). Those orders are never changed.
     */
    private function guardLegacyPacks(Collection $allLines, array $quantities, array $reserved): void
    {
        $groups = $allLines
            ->filter(fn ($l) => $l->pack_id === null && preg_match('/ \(Bundle: (.+)\)$/u', (string) $l->product_name))
            ->groupBy(function ($l) {
                preg_match('/ \(Bundle: (.+)\)$/u', (string) $l->product_name, $m);
                return ($l->seller_order_id ?? 0) . '|' . $m[1];
            });

        foreach ($groups as $key => $lines) {
            $touched = $lines->contains(fn ($l) => (int) ($quantities[$l->id] ?? 0) > 0);
            if (!$touched) {
                continue;
            }
            $whole = $lines->every(fn ($l) => (int) ($quantities[$l->id] ?? 0) + (int) ($reserved[$l->id] ?? 0) >= (int) $l->quantity);
            if (!$whole) {
                $first = $lines->first(fn ($l) => (int) ($quantities[$l->id] ?? 0) > 0) ?? $lines->first();
                throw new ReturnException(__('messages.complaint.legacy_pack_whole', [
                    'item' => preg_replace('/ \(Bundle: .+\)$/u', '', (string) $first->product_name),
                    'pack' => explode('|', $key, 2)[1],
                ]));
            }
        }
    }

    /**
     * After-sale fees per parcel size (Delivery & Fees), as the client sees
     * them before confirming. Frozen on the complaint when it is created.
     *
     * @return array<string, array{return: float, exchange: float}>
     */
    public function afterSaleFees(): array
    {
        $settings = app(\App\Services\Delivery\DeliverySettings::class);
        $out = [];
        foreach (\App\Support\ParcelSize::ALL as $size) {
            $out[$size] = [
                // Not "return_fee": that name is Intigo's refused-parcel fee, never shown to buyers
                'return'   => \App\Support\Millimes::toFloat($settings->afterSaleReturnFee($size)),
                'exchange' => \App\Support\Millimes::toFloat($settings->exchangeFee($size)),
            ];
        }
        return $out;
    }

    /** Return: items − client-paid fee, never below 0. Exchange: nothing is refunded. */
    private function refundFor(Complaint $complaint, float $itemsAmount): float
    {
        if ($complaint->isExchange()) {
            return 0.0;
        }
        return max(0, round($itemsAmount - $complaint->clientFee(), 3));
    }

    /** A client-paid return fee above what the items are worth: refund 0, flagged to the admin. */
    private function feeExceedsRefund(Complaint $complaint, float $itemsAmount): bool
    {
        return !$complaint->isExchange() && $complaint->clientFee() > round($itemsAmount, 3) + 0.0005;
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
        $this->notifyBuyer($complaint, $event);
    }

    /**
     * The client hears about their return: the account (bell + e-mail, per its
     * preferences) or, for a guest, an e-mail only if one was given at checkout.
     * Guests without e-mail follow it on their tracking page.
     */
    public function notifyBuyer(Complaint $complaint, string $event): void
    {
        $complaint->loadMissing('user');
        if ($complaint->user) {
            app(BuyerNotifier::class)->send($complaint->user, new ComplaintNotification($complaint->fresh(), $event));
            return;
        }
        try {
            $email = $complaint->order()->value('guest_email');
            if ($email) {
                Notification::route('mail', $email)->notify((new ComplaintNotification($complaint->fresh(), $event))->locale(app()->getLocale()));
            }
        } catch (\Throwable $e) {
            Log::error("[Return] guest notification {$event} failed: " . $e->getMessage());
        }
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
