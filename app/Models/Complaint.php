<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A return request ("réclamation"). The only resolution is return + refund:
 * a client who wants another item simply reorders.
 *
 *   requested ──seller──► seller_accepted ──admin──► admin_approved
 *       │                                              ▲
 *       ├──seller──► seller_rejected ──client──► escalated ──admin──┘
 *       └──client──► cancelled          (admin may override any decision)
 *
 *   admin_approved → pickup_scheduled → picked_up → returned_to_seller → refunded
 *   (received & inspected: restock)           (finance + order status + money)
 *
 * Transitions, money, stock and notifications live in App\Services\Returns\ReturnService.
 * Legacy "exchange" records stay readable; they can only be closed.
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

    const VALID_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SELLER_ACCEPTED, self::STATUS_SELLER_REJECTED,
        self::STATUS_ESCALATED, self::STATUS_ADMIN_APPROVED, self::STATUS_PICKUP_SCHEDULED,
        self::STATUS_PICKED_UP, self::STATUS_RETURNED_TO_SELLER, self::STATUS_REFUNDED,
        self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_CLOSED,
    ];

    /** Allowed next statuses — nothing can be skipped. */
    const TRANSITIONS = [
        self::STATUS_REQUESTED          => [self::STATUS_SELLER_ACCEPTED, self::STATUS_SELLER_REJECTED, self::STATUS_ADMIN_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_SELLER_ACCEPTED    => [self::STATUS_ADMIN_APPROVED, self::STATUS_REJECTED],
        self::STATUS_SELLER_REJECTED    => [self::STATUS_ESCALATED, self::STATUS_ADMIN_APPROVED, self::STATUS_REJECTED],
        self::STATUS_ESCALATED          => [self::STATUS_ADMIN_APPROVED, self::STATUS_REJECTED],
        self::STATUS_ADMIN_APPROVED     => [self::STATUS_PICKUP_SCHEDULED, self::STATUS_CANCELLED, self::STATUS_CLOSED],
        self::STATUS_PICKUP_SCHEDULED   => [self::STATUS_PICKED_UP, self::STATUS_CANCELLED, self::STATUS_CLOSED],
        self::STATUS_PICKED_UP          => [self::STATUS_RETURNED_TO_SELLER, self::STATUS_CLOSED],
        self::STATUS_RETURNED_TO_SELLER => [self::STATUS_REFUNDED],
    ];

    /** Still in progress (counts as "open" for the client). */
    const OPEN_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SELLER_ACCEPTED, self::STATUS_SELLER_REJECTED, self::STATUS_ESCALATED,
        self::STATUS_ADMIN_APPROVED, self::STATUS_PICKUP_SCHEDULED, self::STATUS_PICKED_UP, self::STATUS_RETURNED_TO_SELLER,
    ];

    /** Waiting for an admin decision. */
    const ADMIN_DECISION_STATUSES = [self::STATUS_SELLER_ACCEPTED, self::STATUS_ESCALATED];

    /** Items in these returns are reserved: they can't be requested again. */
    const BLOCKING_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SELLER_ACCEPTED, self::STATUS_SELLER_REJECTED, self::STATUS_ESCALATED,
        self::STATUS_ADMIN_APPROVED, self::STATUS_PICKUP_SCHEDULED, self::STATUS_PICKED_UP,
        self::STATUS_RETURNED_TO_SELLER, self::STATUS_REFUNDED,
    ];

    // ── Resolution ─────────────────────────────────────────────────────────

    const RESOLUTION_RETURN_REFUND = 'return_refund';
    /** Legacy only — no new exchange can be created. */
    const RESOLUTION_EXCHANGE      = 'exchange';

    // ── Rules ──────────────────────────────────────────────────────────────

    /** How long after delivery a client can request a return. */
    const COMPLAINT_WINDOW_HOURS = 48;

    /** How long after the seller's refusal the client can escalate. */
    const ESCALATION_WINDOW_DAYS = 7;

    const COMPLAINT_TYPES = [
        'wrong_product'   => 'Wrong product received',
        'wrong_size'      => 'Wrong size',
        'wrong_color'     => 'Wrong color',
        'damaged_product' => 'Damaged / defective product',
        'other'           => 'Other',
    ];

    /** Reasons where the seller is at fault → the seller pays the return shipping. */
    const SELLER_FAULT_TYPES = ['wrong_product', 'wrong_size', 'wrong_color', 'damaged_product'];

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
    ];

    protected $casts = [
        'order_item_ids'      => 'array',
        'image_paths'         => 'array',
        'return_shipping_fee' => 'decimal:3',
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

    protected $appends = ['image_url', 'image_urls', 'can_escalate'];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()   { return $this->belongsTo(User::class); }
    public function order()  { return $this->belongsTo(Order::class); }
    public function seller() { return $this->belongsTo(User::class, 'seller_id'); }
    public function items()  { return $this->hasMany(ComplaintItem::class); }
    public function events() { return $this->hasMany(ComplaintEvent::class)->orderBy('id'); }

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
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isExchange(): bool
    {
        return $this->resolution_type === self::RESOLUTION_EXCHANGE;
    }

    public function isReturnRefund(): bool
    {
        return !$this->isExchange();
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
