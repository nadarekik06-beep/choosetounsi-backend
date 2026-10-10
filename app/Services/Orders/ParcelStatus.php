<?php

namespace App\Services\Orders;

use App\Exceptions\FlashSaleSoldOut;
use App\Exceptions\InsufficientStock;
use App\Models\Order;
use App\Models\SellerAdjustment;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Delivery\DeliverySettings;
use App\Services\Orders\WhatsApp\SellerReminderService;
use App\Services\FinancialSnapshotService;
use App\Services\PromotionService;
use App\Support\Millimes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The parcel workflow: the ONLY place a seller_orders.status changes
 * (one seller_order = one parcel). Every change is validated against
 * TRANSITIONS, applies its money / stock effects, and is written to
 * seller_order_status_history (from → to, who, source, when).
 *
 *   pending ─▶ confirmed ─▶ handed_to_courier ─▶ out_for_delivery ─▶ delivered ─▶ refunded
 *      │           │                              (= shipped)    └─▶ refused ─▶ returned_to_seller
 *      └───────────┴─▶ cancelled  (only before the courier has it; may be re-opened)
 *
 *   cancelled          never handed to the courier: no cash, no agency fee, no
 *                      commission, no payout; stock and flash units back now.
 *   delivered          the courier collected the cash (cash_collected_at); payable
 *                      only once the admin confirms the remittance.
 *   refused            refused at the door: no cash, no commission, no payout. The
 *                      agency fee (admin setting, may be 0) is a platform delivery
 *                      loss, or a negative SellerAdjustment on the seller's next
 *                      settlement. Stock stays out until the parcel is back:
 *   returned_to_seller refused parcel back at the seller: stock and flash units back.
 *   refunded           full return through the return flow (ReturnService).
 *
 * The delivery agency API will call carrierUpdate() with its raw status code,
 * mapped onto these statuses (CARRIER_MAP, to fill in when the API is wired).
 */
class ParcelStatus
{
    public const STATUSES = [
        'pending', 'confirmed', 'handed_to_courier', 'out_for_delivery', 'delivered',
        'refused', 'returned_to_seller', 'cancelled', 'refunded',
    ];

    /** from → allowed targets. 'completed' is the legacy "delivered". */
    public const TRANSITIONS = [
        'pending'            => ['confirmed', 'cancelled'],
        'confirmed'          => ['pending', 'handed_to_courier', 'out_for_delivery', 'cancelled'],
        'handed_to_courier'  => ['out_for_delivery'],
        'out_for_delivery'   => ['delivered', 'refused'],
        'delivered'          => ['refunded'],
        'completed'          => ['refunded'],
        'refused'            => ['returned_to_seller'],
        'returned_to_seller' => [],
        'cancelled'          => ['pending', 'confirmed'],   // re-opened: stock taken back
        'refunded'           => [],
    ];

    /** Still before the courier: the only steps a parcel can be cancelled from. */
    public const BEFORE_COURIER = ['pending', 'confirmed'];

    /**
     * What a seller may do: confirm, hand the parcel to the courier, or cancel
     * it while the courier doesn't have it. Shipped / delivered / refused come
     * from the admin (later the agency API); re-opening a cancel is admin-only.
     */
    public const SELLER_TARGETS = ['confirmed', 'handed_to_courier', 'cancelled'];

    /** Settlement batches whose money is committed: their parcels are frozen. */
    private const COMMITTED_BATCHES = ['draft', 'confirmed', 'paid'];

    /**
     * Outcomes an order-wide change walks past instead of failing on
     * (a cancelled parcel is only re-opened by an explicit pending / confirmed).
     */
    private const CLOSED = ['refused', 'returned_to_seller', 'refunded', 'cancelled'];

    public const SOURCES = ['admin', 'seller', 'courier', 'api', 'system'];

    /** Delivery agency status code → our status. Empty until the API integration. */
    public const CARRIER_MAP = [];

    public function __construct(
        private DeliverySettings $settings,
        private OrderStock       $stock,
        private PromotionService $promotions,
    ) {}

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to, ?int $parcelId = null): void
    {
        if (!self::canTransition($from, $to)) {
            throw IllegalTransition::of($from, $to, $parcelId);
        }
    }

    /**
     * Next statuses this actor may set on this parcel: what the admin and
     * seller UIs build their buttons from, so they never offer a move the
     * backend would reject. A parcel whose payout is paid or batched is frozen.
     *
     * @param 'admin'|'seller' $actor
     */
    public static function allowedNext(SellerOrder $parcel, string $actor = 'admin'): array
    {
        if (self::moneyCommitted($parcel)) {
            return [];
        }
        $next = self::TRANSITIONS[$parcel->status] ?? [];
        if ($actor === 'seller') {
            $next = $parcel->status === 'cancelled' ? [] : array_intersect($next, self::SELLER_TARGETS);
        }
        return array_values($next);
    }

    /** Batch reference when the parcel's payout is paid or in a live settlement batch, else null. */
    public static function moneyCommitted(SellerOrder $parcel): ?string
    {
        $batchId = $parcel->getAttribute('settlement_batch_id');
        if ($batchId) {
            $batch = DB::table('settlement_batches')->where('id', $batchId)->first(['batch_reference', 'status']);
            if ($batch && in_array($batch->status, self::COMMITTED_BATCHES, true)) {
                return "{$batch->batch_reference}, {$batch->status}";
            }
        }
        return $parcel->getAttribute('payout_status') === 'paid' ? 'payout paid' : null;
    }

    // ── One parcel ──────────────────────────────────────────────────────────

    /**
     * Move one parcel to $to. Options:
     *   by      User|int|null  who did it (null for system / api)
     *   source  admin | seller | courier | api | system   (default admin)
     *   note    free text kept in the history (and on a refused adjustment)
     *   notify  tell the buyer (default true)
     *
     * @throws IllegalTransition (also: re-open with units sold meanwhile, payout already settled)
     */
    public function transition(SellerOrder|int $parcel, string $to, array $options = []): SellerOrder
    {
        $id = $parcel instanceof SellerOrder ? $parcel->id : $parcel;

        $parcel = DB::transaction(function () use ($id, $to, $options) {
            $locked = SellerOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->apply($locked, $to, $options);
            Order::syncStatusFromParcels((int) $locked->order_id);
            return $locked;
        });

        $this->after([$parcel], $to, $options);
        return $parcel->fresh();
    }

    /**
     * Move every parcel of an order to $to (admin order-wide actions).
     * Parcels already there, and closed ones (refused, returned, refunded,
     * cancelled unless re-opening) are left as they are; any other parcel
     * that can't make the move rejects the whole change.
     *
     * @param  \Closure|null $scope  narrows the parcels (query builder)
     * @return int[] ids of the parcels moved
     * @throws IllegalTransition
     */
    public function transitionOrder(int $orderId, string $to, array $options = [], ?\Closure $scope = null): array
    {
        $reopen = in_array($to, self::TRANSITIONS['cancelled'], true);

        $moved = DB::transaction(function () use ($orderId, $to, $options, $scope, $reopen) {
            Order::whereKey($orderId)->lockForUpdate()->first();
            $parcels = SellerOrder::where('order_id', $orderId)
                ->when($scope, $scope)
                ->orderBy('id')->lockForUpdate()->get()
                ->reject(fn (SellerOrder $so) => $so->status === $to
                    || (in_array($so->status, self::CLOSED, true) && !($so->status === 'cancelled' && $reopen)));

            foreach ($parcels as $so) {
                self::assertTransition($so->status, $to, $so->id);
            }
            foreach ($parcels as $so) {
                $this->apply($so, $to, $options);
            }
            if ($parcels->isNotEmpty()) {
                Order::syncStatusFromParcels($orderId);
            }
            return $parcels->all();
        });

        $this->after($moved, $to, $options);
        return array_map(fn ($so) => $so->id, $moved);
    }

    // ── Named steps (admin endpoints) ──────────────────────────────────────

    public function markDelivered(SellerOrder $parcel, User $admin): SellerOrder
    {
        return $this->transition($parcel, 'delivered', ['by' => $admin, 'source' => 'admin']);
    }

    public function markRefused(SellerOrder $parcel, User $admin, ?string $note = null): SellerOrder
    {
        return $this->transition($parcel, 'refused', ['by' => $admin, 'source' => 'admin', 'note' => $note]);
    }

    public function markReturnedToSeller(SellerOrder $parcel, User $admin, ?string $note = null): SellerOrder
    {
        return $this->transition($parcel, 'returned_to_seller', ['by' => $admin, 'source' => 'admin', 'note' => $note]);
    }

    /**
     * Delivery agency update (future API): keeps the raw code and tracking
     * number, then moves the parcel when the code maps onto a new status.
     * An unmapped code is only recorded.
     */
    public function carrierUpdate(SellerOrder $parcel, string $rawStatus, ?string $trackingNumber = null): SellerOrder
    {
        DB::table('seller_orders')->where('id', $parcel->id)->update(array_filter([
            'carrier_status_raw'      => mb_substr($rawStatus, 0, 191),
            'carrier_status_at'       => now(),
            'carrier_tracking_number' => $trackingNumber,
        ], fn ($v) => $v !== null));

        $to = self::CARRIER_MAP[$rawStatus] ?? null;
        $parcel->refresh();
        if ($to === null || $to === $parcel->status) {
            return $parcel;
        }
        return $this->transition($parcel, $to, ['source' => 'api', 'carrier_status_raw' => $rawStatus]);
    }

    /**
     * History row for a status written outside transition() with its own
     * money logic (ReturnService refunding a full return). The caller has
     * validated it with assertTransition().
     */
    public static function record(SellerOrder $parcel, ?string $from, string $to, array $options = []): void
    {
        $by = $options['by'] ?? null;
        DB::table('seller_order_status_history')->insert([
            'seller_order_id'    => $parcel->id,
            'order_id'           => $parcel->order_id,
            'from_status'        => $from,
            'to_status'          => $to,
            'source'             => in_array($options['source'] ?? null, self::SOURCES, true) ? $options['source'] : 'admin',
            'changed_by'         => $by instanceof User ? $by->id : $by,
            'carrier_status_raw' => isset($options['carrier_status_raw']) ? mb_substr($options['carrier_status_raw'], 0, 191) : null,
            'note'               => isset($options['note']) ? mb_substr($options['note'], 0, 255) : null,
            'created_at'         => now(),
        ]);
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /** Validate, write and apply the effects of one move. Inside a transaction, $so locked. */
    private function apply(SellerOrder $so, string $to, array $options): void
    {
        $from = $so->status;
        self::assertTransition($from, $to, $so->id);

        // Money already settled: the status is frozen (a return goes through ReturnService)
        if ($batch = self::moneyCommitted($so)) {
            throw IllegalTransition::moneyCommitted($so->id, $from, $to, $batch);
        }

        $now    = now();
        $fields = ['status' => $to];

        // Re-opened after a cancel: its lines take their stock and flash-sale
        // units back first; anything sold meanwhile rejects the re-open (the
        // caller's transaction rolls back whatever was already taken).
        if ($from === 'cancelled') {
            try {
                $this->stock->reclaimForSellerOrders([$so->id]);
                $this->promotions->reclaimForSellerOrders([$so->id]);
            } catch (InsufficientStock $e) {
                throw IllegalTransition::reopenUnavailable($so->id, $to, "only {$e->available} left of {$e->label}");
            } catch (FlashSaleSoldOut $e) {
                throw IllegalTransition::reopenUnavailable($so->id, $to, "the flash sale on {$e->productName} is sold out");
            }
        }

        switch ($to) {
            case 'delivered':
                $fields['delivery_confirmed_at'] = $so->getAttribute('delivery_confirmed_at') ?? $now;
                $fields['cash_collected_at']     = $so->getAttribute('cash_collected_at') ?? $now;
                break;

            case 'refused':
                $feeM  = $this->settings->refusedFee();
                // CHOOSE'Tounsi's own parcel: nobody else to bill
                $payer = $so->isPlatformParcel() ? 'platform' : $this->settings->refusedFeePayer();
                $fields += [
                    'refused_at'          => $now,
                    'refused_agency_fee'  => Millimes::toDecimal($feeM),
                    'refused_fee_paid_by' => $payer,
                ];
                if ($payer === 'seller' && $feeM > 0) {
                    $note = $options['note'] ?? null;
                    SellerAdjustment::create([
                        'seller_id'       => $so->seller_id,
                        'seller_order_id' => $so->id,
                        'type'            => SellerAdjustment::TYPE_REFUSED_PARCEL,
                        'amount'          => Millimes::toDecimal(-$feeM),
                        'description'     => 'Parcel refused by the client: delivery agency fee' . ($note ? " ({$note})" : ''),
                        'created_by'      => $this->actorId($options),
                    ]);
                }
                break;

            case 'returned_to_seller':
                $fields['returned_to_seller_at'] = $now;
                break;
        }

        // Eloquent save: forecast / profit-goal triggers run. SellerOrderObserver
        // repeats the cancel / re-open effects below; both are idempotent.
        $so->forceFill($fields)->save();

        if ($to === 'cancelled' || $to === 'returned_to_seller') {
            $this->stock->releaseForSellerOrders([$so->id], $to === 'cancelled' ? OrderStock::CANCELLED : OrderStock::REFUSED);
            $this->promotions->releaseForSellerOrders([$so->id]);
        }

        // Nothing is owed on a parcel that earns nothing; re-opened, it waits for cash again
        if (in_array($to, SellerOrder::NOT_SHIPPED, true)) {
            DB::table('seller_orders')->where('id', $so->id)->whereNull('settlement_batch_id')
                ->whereIn('payout_status', ['pending', 'ready'])
                ->update(['payout_status' => 'cancelled', 'updated_at' => $now]);
        } elseif ($from === 'cancelled') {
            SellerOrder::syncPayoutWithStatus([$so->id], false);
        }

        self::record($so, $from, $to, $options);
    }

    /** After the commit: integrity check, seller WhatsApp reminders and buyer notification. */
    private function after(array $parcels, string $to, array $options): void
    {
        if (!$parcels) return;

        // Admin confirmed: the seller is asked on WhatsApp to prepare it. Any
        // other move (shipped, cancelled, back to pending…) stops the reminders.
        try {
            $reminders = app(SellerReminderService::class);
            if ($to !== 'confirmed') {
                $reminders->cancel(array_map(fn ($so) => $so->id, $parcels));
            } elseif (($options['source'] ?? 'admin') === 'admin') {
                $reminders->schedule($parcels);
            }
        } catch (\Throwable $e) {
            Log::warning('[ParcelStatus] seller WhatsApp reminders failed: ' . $e->getMessage());
        }

        if ($to === 'delivered') {
            foreach ($parcels as $so) {
                FinancialSnapshotService::checkParcel($so->id);
            }
        }

        if ($options['notify'] ?? true) {
            $reason = match ($options['source'] ?? 'admin') {
                'seller' => BuyerOrderNotifier::REASON_SELLER,
                'system' => $options['reason'] ?? BuyerOrderNotifier::REASON_ADMIN,
                default  => BuyerOrderNotifier::REASON_ADMIN,
            };
            try {
                app(BuyerOrderNotifier::class)->statusChanged((int) $parcels[0]->order_id, array_map(fn ($so) => $so->id, $parcels), $reason);
            } catch (\Throwable $e) {
                Log::warning('[ParcelStatus] buyer notification failed: ' . $e->getMessage());
            }
        }
    }

    private function actorId(array $options): ?int
    {
        $by = $options['by'] ?? null;
        return $by instanceof User ? $by->id : $by;
    }
}
