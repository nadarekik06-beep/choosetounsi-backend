<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model: RefundDeliveryTask
 *
 * Lifecycle: pending → assigned → picked_up → completed
 *
 * Customer info (name, phone, wilaya, address) is resolved via:
 *   $task->complaint->order  (order has phone, wilaya, address)
 *   $task->complaint->order->user  (user has name)
 *
 * Seller info (name, business_name) is resolved via:
 *   $task->seller (User)
 *   $task->seller->sellerApplication
 *
 * Kept as snapshots (delivery convenience, avoids deep joins in mobile app):
 *   seller_phone, seller_wilaya, seller_city
 *   items_summary, complaint_type, complaint_description, complaint_image_url
 */
class RefundDeliveryTask extends Model
{
    use HasFactory;

    protected $table = 'refund_delivery_tasks';

    const STATUS_PENDING   = 'pending';
    const STATUS_ASSIGNED  = 'assigned';
    const STATUS_PICKED_UP = 'picked_up';
    const STATUS_COMPLETED = 'completed';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ASSIGNED,
        self::STATUS_PICKED_UP,
        self::STATUS_COMPLETED,
    ];

    const TRANSITIONS = [
        self::STATUS_ASSIGNED  => [self::STATUS_PICKED_UP],
        self::STATUS_PICKED_UP => [self::STATUS_COMPLETED],
    ];

    protected $fillable = [
        'complaint_id',
        'order_id',
        'seller_id',
        // customer_name, customer_phone, customer_wilaya, customer_address REMOVED
        // seller_name, seller_business_name REMOVED
        'seller_phone',
        'seller_wilaya',
        'seller_city',
        'items_summary',
        'complaint_type',
        'complaint_description',
        'complaint_image_url',
        'status',
        'delivery_guy_id',
        'assigned_by',
        'assigned_at',
        'picked_up_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'items_summary' => 'array',
        'assigned_at'   => 'datetime',
        'picked_up_at'  => 'datetime',
        'completed_at'  => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function deliveryGuy()
    {
        return $this->belongsTo(User::class, 'delivery_guy_id');
    }

    public function assignedByUser()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopePending($q)   { return $q->where('status', self::STATUS_PENDING); }
    public function scopeAssigned($q)  { return $q->where('status', self::STATUS_ASSIGNED); }
    public function scopePickedUp($q)  { return $q->where('status', self::STATUS_PICKED_UP); }
    public function scopeCompleted($q) { return $q->where('status', self::STATUS_COMPLETED); }

    public function scopeForDeliveryGuy($q, int $deliveryGuyId)
    {
        return $q->where('delivery_guy_id', $deliveryGuyId);
    }

    public function scopeActive($q)
    {
        return $q->whereIn('status', [self::STATUS_ASSIGNED, self::STATUS_PICKED_UP]);
    }

    // ── Status Helpers ─────────────────────────────────────────────────────

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isAssigned(): bool  { return $this->status === self::STATUS_ASSIGNED; }
    public function isPickedUp(): bool  { return $this->status === self::STATUS_PICKED_UP; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? []);
    }

    // ── Action Helpers ─────────────────────────────────────────────────────

    public function assignTo(int $deliveryGuyId, int $assignedById, ?string $notes = null): void
    {
        $this->update([
            'status'          => self::STATUS_ASSIGNED,
            'delivery_guy_id' => $deliveryGuyId,
            'assigned_by'     => $assignedById,
            'assigned_at'     => now(),
            'notes'           => $notes ?? $this->notes,
        ]);
    }

    public function markPickedUp(): void
    {
        $this->update([
            'status'       => self::STATUS_PICKED_UP,
            'picked_up_at' => now(),
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status'       => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}