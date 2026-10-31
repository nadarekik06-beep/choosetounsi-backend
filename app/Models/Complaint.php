<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An after-sale request ("réclamation"): a RETURN (item back, client refunded)
 * or an EXCHANGE (the courier delivers another variant of the same product and
 * collects the old item in the same trip — Intigo exchange parcel).
 *
 *   requested ──seller──► seller_accepted ──admin──► admin_approved
 *       │                                              ▲
 *       ├──seller──► seller_rejected ──client──► escalated ──admin──┘
 *       └──client──► cancelled          (admin may override any decision)
 *
 *   return:   admin_approved → pickup_scheduled → picked_up → returned_to_seller → refunded
 *             (received & inspected: restock)            (finance + order status + money)
 *   exchange: admin_approved (replacement stock held, Intigo parcel booked)
 *             → exchange_in_transit → exchanged (replacement delivered, old item collected)
 *             → exchange_completed (the seller confirms the old item is back: restock)
 *             exchange_in_transit → exchange_failed (refused at the door) → admin decides:
 *             retry (exchange_in_transit), switch to a return (admin_approved) or cancel.
 *
 * Who pays the after-sale parcel: the seller when at fault (SELLER_FAULT_TYPES),
 * else the client (deducted from the refund, or collected as cash on the
 * exchange parcel). One fee per complaint, at its largest parcel size.
 *
 * Transitions, money, stock and notifications live in App\Services\Returns\ReturnService.
 * Legacy exchange records (before fees per size: fee_size null) stay as they
 * are; they can only be closed.
 */
class Complaint extends Model
{
    use HasFactory;

    // ── Status ─────────────────────────────────────────────────────────────

    const STATUS_REQUESTED          = 'requested';
    const STATUS_SELLER_ACCEPTED    = 'seller_accepted';
    const STATUS_SELLER_REJECTED    = 'seller_rejected';
    const STATUS_ESCALATED          = 'escalated';
    const STATUS_ADMIN_APPROVED     = 'admin_approved';
    const STATUS_PICKUP_SCHEDULED   = 'pickup_scheduled';
    const STATUS_PICKED_UP          = 'picked_up';
    const STATUS_RETURNED_TO_SELLER = 'returned_to_seller';
    const STATUS_REFUNDED           = 'refunded';
    const STATUS_REJECTED           = 'rejected';
    const STATUS_CANCELLED          = 'cancelled';
    const STATUS_CLOSED             = 'closed';   // legacy exchange, done

    // Exchange path
    const STATUS_EXCHANGE_IN_TRANSIT = 'exchange_in_transit';
    const STATUS_EXCHANGED           = 'exchanged';            // replacement delivered, old item on its way back
    const STATUS_EXCHANGE_FAILED     = 'exchange_failed';      // refused at the door: the admin decides
    const STATUS_EXCHANGE_COMPLETED  = 'exchange_completed';   // the seller confirmed the old item is back

    const VALID_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SELLER_ACCEPTED, self::STATUS_SELLER_REJECTED,
        self::STATUS_ESCALATED, self::STATUS_ADMIN_APPROVED, self::STATUS_PICKUP_SCHEDULED,
        self::STATUS_PICKED_UP, self::STATUS_RETURNED_TO_SELLER, self::STATUS_REFUNDED,
        self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_CLOSED,
        self::STATUS_EXCHANGE_IN_TRANSIT, self::STATUS_EXCHANGED, self::STATUS_EXCHANGE_FAILED, self::STATUS_EXCHANGE_COMPLETED,
    ];

    /** Allowed next statuses — nothing can be skipped (filtered by resolution in canTransitionTo). */
    const TRANSITIONS = [
        self::STATUS_REQUESTED           => [self::STATUS_SELLER_ACCEPTED, self::STATUS_SELLER_REJECTED, self::STATUS_ADMIN_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_SELLER_ACCEPTED     => [self::STATUS_ADMIN_APPROVED, self::STATUS_REJECTED],
        self::STATUS_SELLER_REJECTED     => [self::STATUS_ESCALATED, self::STATUS_ADMIN_APPROVED, self::STATUS_REJECTED],
        self::STATUS_ESCALATED           => [self::STATUS_ADMIN_APPROVED, self::STATUS_REJECTED],
        self::STATUS_ADMIN_APPROVED      => [self::STATUS_PICKUP_SCHEDULED, self::STATUS_EXCHANGE_IN_TRANSIT, self::STATUS_CANCELLED, self::STATUS_CLOSED],
        self::STATUS_PICKUP_SCHEDULED    => [self::STATUS_PICKED_UP, self::STATUS_CANCELLED, self::STATUS_CLOSED],
        self::STATUS_PICKED_UP           => [self::STATUS_RETURNED_TO_SELLER, self::STATUS_CLOSED],
        self::STATUS_RETURNED_TO_SELLER  => [self::STATUS_REFUNDED],
        self::STATUS_EXCHANGE_IN_TRANSIT => [self::STATUS_EXCHANGED, self::STATUS_EXCHANGE_FAILED],
        self::STATUS_EXCHANGED           => [self::STATUS_EXCHANGE_COMPLETED],
        // Admin decision: retry, switch to a return (the client keeps the old item until the pick-up), cancel
        self::STATUS_EXCHANGE_FAILED     => [self::STATUS_EXCHANGE_IN_TRANSIT, self::STATUS_ADMIN_APPROVED, self::STATUS_CANCELLED],
    ];

    /** Statuses only a return goes through. */
    const RETURN_ONLY_STATUSES = [self::STATUS_PICKUP_SCHEDULED, self::STATUS_PICKED_UP, self::STATUS_RETURNED_TO_SELLER, self::STATUS_REFUNDED];

    /** Statuses only an exchange goes through. */
    const EXCHANGE_ONLY_STATUSES = [self::STATUS_EXCHANGE_IN_TRANSIT, self::STATUS_EXCHANGED, self::STATUS_EXCHANGE_FAILED, self::STATUS_EXCHANGE_COMPLETED];

    /** Still in progress (counts as "open" for the client). */
    const OPEN_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SELLER_ACCEPTED, self::STATUS_SELLER_REJECTED, self::STATUS_ESCALATED,
        self::STATUS_ADMIN_APPROVED, self::STATUS_PICKUP_SCHEDULED, self::STATUS_PICKED_UP, self::STATUS_RETURNED_TO_SELLER,
        self::STATUS_EXCHANGE_IN_TRANSIT, self::STATUS_EXCHANGED, self::STATUS_EXCHANGE_FAILED,
    ];

    /** Waiting for an admin decision. */
    const ADMIN_DECISION_STATUSES = [self::STATUS_SELLER_ACCEPTED, self::STATUS_ESCALATED, self::STATUS_EXCHANGE_FAILED];

    /** Items in these returns are reserved: they can't be requested again. */
    const BLOCKING_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SELLER_ACCEPTED, self::STATUS_SELLER_REJECTED, self::STATUS_ESCALATED,
        self::STATUS_ADMIN_APPROVED, self::STATUS_PICKUP_SCHEDULED, self::STATUS_PICKED_UP,
        self::STATUS_RETURNED_TO_SELLER, self::STATUS_REFUNDED,
        self::STATUS_EXCHANGE_IN_TRANSIT, self::STATUS_EXCHANGED, self::STATUS_EXCHANGE_FAILED, self::STATUS_EXCHANGE_COMPLETED,
    ];

    // ── Resolution ─────────────────────────────────────────────────────────

    const RESOLUTION_RETURN_REFUND = 'return_refund';
    const RESOLUTION_EXCHANGE      = 'exchange';
    const RESOLUTIONS              = [self::RESOLUTION_RETURN_REFUND, self::RESOLUTION_EXCHANGE];

    /** After this many days in "exchanged" without the seller's confirmation: "Old item not received". */
    const OLD_ITEM_DAYS = 7;

    // Exchange parcel at Intigo (exchange_sync_status)
    const EXCHANGE_SENDING     = 'sending';
    const EXCHANGE_SENT        = 'sent';
    const EXCHANGE_MANUAL      = 'manual';        // book it by hand in the Intigo dashboard
    const EXCHANGE_NEEDS_CHECK = 'needs_check';   // no answer: it may exist — check Intigo, paste the nid
    const EXCHANGE_SEND_FAILED = 'failed';        // Intigo refused it (reason in exchange_sync_error)
    const EXCHANGE_CANCELLED   = 'cancelled';     // cancelled by Intigo

    // ── Rules ──────────────────────────────────────────────────────────────

    /** How long after delivery a client can request a return. */
    const COMPLAINT_WINDOW_HOURS = 48;

    /** How long after the seller's refusal the client can escalate. */
    const ESCALATION_WINDOW_DAYS = 7;

    const COMPLAINT_TYPES = [
        'wrong_product'      => 'Wrong product received',
        'wrong_size'         => 'Wrong size sent',
        'wrong_color'        => 'Wrong color sent',
        'damaged_product'    => 'Damaged / defective product',
        'changed_mind'       => 'Changed my mind',
        'doesnt_fit'         => "Size doesn't fit",
        'prefer_other_color' => 'Prefer another color',
        'other'              => 'Other',
    ];

    /** Reasons where the seller is at fault → the seller pays the after-sale parcel. */
    const SELLER_FAULT_TYPES = ['wrong_product', 'wrong_size', 'wrong_color', 'damaged_product'];

    /** The client's choice → the client pays (the admin may still switch the payer before approving). */
    const CLIENT_CHOICE_TYPES = ['changed_mind', 'doesnt_fit', 'prefer_other_color', 'other'];

    const PAYERS = ['client', 'seller'];

    /** cash = paid back by the courier at pick-up (cash on delivery orders). */
    const REFUND_METHODS = ['cash', 'wallet', 'bank_transfer', 'd17', 'original'];

    const CONDITION_RESALEABLE = 'resaleable';
    const CONDITION_DAMAGED    = 'damaged';

    // Delivery task mirror (refund_delivery_tasks.status)
    const REFUND_STATUS_PENDING   = 'pending';
    const REFUND_STATUS_ASSIGNED  = 'assigned';
    const REFUND_STATUS_PICKED_UP = 'picked_up';
    const REFUND_STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'reference', 'user_id', 'order_id', 'order_item_ids', 'seller_id',
        'complaint_type', 'resolution_type', 'return_scope', 'shipping_payer', 'return_shipping_fee',
        'items_amount', 'refund_amount', 'refund_method', 'refund_reference',
        'other_reason', 'description', 'image_path', 'image_paths', 'status',
        'rejection_reason', 'seller_note', 'seller_decision', 'seller_decided_at',
        'escalated_at', 'escalation_note', 'admin_decided_by', 'admin_decided_at', 'admin_note',
        'pickup_scheduled_at', 'pickup_note', 'picked_up_at', 'received_at', 'received_by', 'reception_note',
        'refunded_at', 'refunded_by', 'finance_applied_at',
        'reviewed_at', 'resolved_at', 'refund_status', 'refund_task_id',
        'fee_size', 'exchange_fee', 'fee_exceeds_refund',
        'intigo_exchange_nid', 'exchange_sync_status', 'exchange_sync_error',
        'intigo_exchange_status_code', 'intigo_exchange_status_label', 'intigo_exchange_status_at',
        'exchange_shipped_at', 'exchanged_at', 'exchange_failed_at', 'old_item_received_at',
    ];

    protected $casts = [
        'order_item_ids'      => 'array',
        'image_paths'         => 'array',
        'return_shipping_fee' => 'decimal:3',
        'exchange_fee'        => 'decimal:3',
        'fee_exceeds_refund'  => 'boolean',
        'intigo_exchange_status_code' => 'integer',
        'intigo_exchange_status_at'   => 'datetime',
        'exchange_shipped_at'  => 'datetime',
        'exchanged_at'         => 'datetime',
        'exchange_failed_at'   => 'datetime',
        'old_item_received_at' => 'datetime',
        'items_amount'        => 'decimal:3',
        'refund_amount'       => 'decimal:3',
        'reviewed_at'         => 'datetime',
        'resolved_at'         => 'datetime',
        'seller_decided_at'   => 'datetime',
        'escalated_at'        => 'datetime',
        'admin_decided_at'    => 'datetime',
        'pickup_scheduled_at' => 'datetime',
        'picked_up_at'        => 'datetime',
        'received_at'         => 'datetime',
        'refunded_at'         => 'datetime',
        'finance_applied_at'  => 'datetime',
    ];

    protected $appends = ['image_url', 'image_urls', 'can_escalate', 'old_item_overdue', 'is_legacy_exchange'];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()   { return $this->belongsTo(User::class); }
    public function order()  { return $this->belongsTo(Order::class); }
    public function seller() { return $this->belongsTo(User::class, 'seller_id'); }
    public function items()  { return $this->hasMany(ComplaintItem::class); }
    public function events() { return $this->hasMany(ComplaintEvent::class)->orderBy('id'); }
    public function costs()  { return $this->hasMany(ComplaintCost::class); }

    public function refundTask()
    {
        return $this->hasOne(RefundDeliveryTask::class, 'complaint_id');
    }

    /**
     * Lazy-load only: under with() the ids aren't known yet and every line of
     * the order would come back. Read endpoints use withItemSnapshots().
     */
    public function complainedItems()
    {
        $ids = $this->order_item_ids ?? [];
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id')
            ->when(!empty($ids), fn($q) => $q->whereIn('id', $ids));
    }

    /**
     * Sets complained_items on each complaint: the returned lines, each as bought
     * (OrderItem::purchaseSnapshot — image, name, variant, price) plus what this
     * return covers: return_quantity, refund line amount, condition, restock.
     *
     * @param  self|iterable<self> $complaints
     */
    public static function withItemSnapshots($complaints)
    {
        $list = $complaints instanceof self ? collect([$complaints]) : collect($complaints instanceof \Illuminate\Pagination\AbstractPaginator ? $complaints->items() : $complaints);
        if ($list->isEmpty()) return $complaints;

        $lines = OrderItem::whereIn('order_id', $list->pluck('order_id')->unique())
            ->with([
                'sellerOrder:id,seller_id',
                'product' => fn($q) => $q->withTrashed()->with(['images', 'variants.attributeOptions.attribute']),
                'variant.attributeOptions.attribute',
            ])
            ->orderBy('id')->get()->groupBy('order_id');

        $returned = ComplaintItem::whereIn('complaint_id', $list->pluck('id'))->get()->groupBy('complaint_id');

        foreach ($list as $complaint) {
            $rows  = collect($lines[$complaint->order_id] ?? []);
            $mine  = collect($returned[$complaint->id] ?? [])->keyBy('order_item_id');
            $rows  = $mine->isNotEmpty()
                ? $rows->whereIn('id', $mine->keys()->all())
                : ($complaint->order_item_ids
                    ? $rows->whereIn('id', $complaint->order_item_ids)
                    : $rows->filter(fn($i) => !$complaint->seller_id || !$i->sellerOrder || (int) $i->sellerOrder->seller_id === (int) $complaint->seller_id));

            $complaint->setAttribute('complained_items', $rows->map(function (OrderItem $line) use ($mine) {
                $snap = $line->purchaseSnapshot();
                $ci   = $mine[$line->id] ?? null;
                return $snap + [
                    'return_quantity'    => $ci ? (int) $ci->quantity : (int) $snap['quantity'],
                    'return_unit_price'  => $ci ? round((float) $ci->net_amount / max(1, (int) $ci->quantity), 3) : (float) $snap['unit_price'],
                    'return_amount'      => $ci ? (float) $ci->net_amount : (float) ($snap['total'] ?? 0),
                    'condition'          => $ci?->condition,
                    'restocked_quantity' => $ci ? (int) $ci->restocked_quantity : 0,
                    // Exchange: the variant sent instead
                    'replacement_variant_id' => $ci?->replacement_variant_id ? (int) $ci->replacement_variant_id : null,
                    'replacement_label'      => $ci?->replacement_label,
                    'replacement_reserved'   => (bool) ($ci?->replacement_reserved_at && !$ci?->replacement_released_at),
                ];
            })->values()->all());
        }

        return $complaints;
    }

    /**
     * "T-shirt — Rouge / M × 1" per returned line — for e-mails and the slip.
     * Works on a copy: complained_items isn't a column and must never be saved.
     */
    public function itemSummaries(): array
    {
        $copy = clone $this;
        self::withItemSnapshots($copy);

        return array_map(
            fn($i) => trim(($i['product_name'] ?? '') . ($i['variant_label'] ? " — {$i['variant_label']}" : '')) . ' × ' . ($i['return_quantity'] ?? $i['quantity']),
            $copy->getAttribute('complained_items') ?? []
        );
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::url($this->image_path) : null;
    }

    /** Every proof photo (the first one is image_path). */
    public function getImageUrlsAttribute(): array
    {
        $paths = $this->image_paths ?: ($this->image_path ? [$this->image_path] : []);
        return array_values(array_map(fn($p) => Storage::url($p), $paths));
    }

    public function getCanEscalateAttribute(): bool
    {
        return $this->canEscalate();
    }

    /** Exchanged more than OLD_ITEM_DAYS ago and the seller hasn't confirmed the old item is back. */
    public function getOldItemOverdueAttribute(): bool
    {
        return $this->status === self::STATUS_EXCHANGED
            && $this->exchanged_at
            && $this->exchanged_at->lt(now()->subDays(self::OLD_ITEM_DAYS));
    }

    public function getIsLegacyExchangeAttribute(): bool
    {
        return $this->isLegacyExchange();
    }

    public function getTypeLabel(): string
    {
        if ($this->complaint_type === 'other' && $this->other_reason) {
            return $this->other_reason;
        }
        return self::COMPLAINT_TYPES[$this->complaint_type] ?? $this->complaint_type;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeStatus($q, string $status)    { return $q->where('status', $status); }
    public function scopeOpen($q)                      { return $q->whereIn('status', self::OPEN_STATUSES); }
    public function scopeForSeller($q, int $sellerId)  { return $q->where('seller_id', $sellerId); }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /** Next statuses for this complaint's resolution (a return never takes the exchange path, and back). */
    public function allowedTransitions(): array
    {
        $next = self::TRANSITIONS[$this->status] ?? [];
        if ($this->isLegacyExchange()) {
            return array_values(array_intersect($next, [self::STATUS_CLOSED, self::STATUS_CANCELLED, self::STATUS_REJECTED]));
        }
        $blocked = $this->isExchange()
            ? [...self::RETURN_ONLY_STATUSES, self::STATUS_CLOSED]
            : [...self::EXCHANGE_ONLY_STATUSES, self::STATUS_CLOSED];
        return array_values(array_diff($next, $blocked));
    }

    public function isExchange(): bool
    {
        return $this->resolution_type === self::RESOLUTION_EXCHANGE;
    }

    /** Filed under the old exchange policy (before fees per size): only closable. */
    public function isLegacyExchange(): bool
    {
        return $this->isExchange() && $this->fee_size === null;
    }

    public function isReturnRefund(): bool
    {
        return !$this->isExchange();
    }

    public static function payerFor(string $complaintType): string
    {
        return in_array($complaintType, self::SELLER_FAULT_TYPES, true) ? 'seller' : 'client';
    }

    /** The after-sale parcel fee of this case (frozen at request): exchange or return fee of its size. */
    public function caseFee(): float
    {
        return $this->isExchange() ? (float) $this->exchange_fee : (float) $this->return_shipping_fee;
    }

    /** What the client pays: deducted from the refund (return) or cash to the courier (exchange). */
    public function clientFee(): float
    {
        return $this->shipping_payer === 'client' ? $this->caseFee() : 0.0;
    }

    /** What comes off the seller's next settlement. */
    public function sellerFee(): float
    {
        return $this->shipping_payer === 'seller' ? $this->caseFee() : 0.0;
    }

    public function sellerCanAct(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function canEscalate(): bool
    {
        return $this->status === self::STATUS_SELLER_REJECTED
            && (!$this->seller_decided_at || $this->seller_decided_at->gt(now()->subDays(self::ESCALATION_WINDOW_DAYS)));
    }

    public function hasRefundTask(): bool
    {
        return !is_null($this->refund_task_id);
    }

    public static function nextReference(int $id): string
    {
        return 'RET-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
