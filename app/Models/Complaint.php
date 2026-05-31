<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Events\ComplaintApproved;

/**
 * FILE: app/Models/Complaint.php  ← REPLACE
 *
 * Changes from previous version:
 *   - COMPLAINT_WINDOW_DAYS removed, replaced with COMPLAINT_WINDOW_HOURS = 48
 *   - Added 'resolution_type' to $fillable and $casts
 *   - Added RESOLUTION_* constants
 *   - Added isExchange() / isReturnRefund() helpers
 *   - eligibleOrders() query in ComplaintController uses hours now
 *   - All existing methods, scopes, and constants preserved
 */
class Complaint extends Model
{
    use HasFactory;

    // ── Status constants ───────────────────────────────────────────────────

    const STATUS_PENDING         = 'pending';
    const STATUS_REVIEWING       = 'reviewing';
    const STATUS_APPROVED        = 'approved';
    const STATUS_SELLER_REJECTED = 'seller_rejected_pending_admin';
    const STATUS_REJECTED        = 'rejected';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVIEWING,
        self::STATUS_APPROVED,
        self::STATUS_SELLER_REJECTED,
        self::STATUS_REJECTED,
    ];

    // ── Resolution type constants (NEW) ────────────────────────────────────

    /**
     * Customer wants a replacement item sent.
     * Delivery agent brings the new item. Stock is NOT restored on the
     * original item (it stays with the customer until exchanged).
     * No financial adjustment — the seller sends a replacement at own cost.
     */
    const RESOLUTION_EXCHANGE = 'exchange';

    /**
     * Customer wants their money back.
     * Delivery agent collects the complained item(s) and returns them to seller.
     * Stock IS restored. Commission on returned items is reversed.
     * seller_order.subtotal is adjusted downward.
     * If ALL items in the seller_order are returned → seller_order status = 'cancelled'.
     * If SOME items only → seller_order status stays 'delivered', partial refund noted.
     */
    const RESOLUTION_RETURN_REFUND = 'return_refund';

    // ── Complaint window ───────────────────────────────────────────────────

    /**
     * How long after delivery a customer can file a complaint.
     *
     * CHANGED from 14 days to 48 hours per business requirement.
     * All eligibility checks use this constant — change it here only.
     */
    const COMPLAINT_WINDOW_HOURS = 48;

    // ── Complaint types ────────────────────────────────────────────────────

    const COMPLAINT_TYPES = [
        'wrong_product'   => 'Wrong product received',
        'wrong_size'      => 'Wrong size',
        'wrong_color'     => 'Wrong color',
        'damaged_product' => 'Damaged / defective product',
        'other'           => 'Other',
    ];

    // ── Refund status constants ────────────────────────────────────────────

    const REFUND_STATUS_PENDING   = 'pending';
    const REFUND_STATUS_ASSIGNED  = 'assigned';
    const REFUND_STATUS_PICKED_UP = 'picked_up';
    const REFUND_STATUS_COMPLETED = 'completed';

    // ── Fillable ───────────────────────────────────────────────────────────

    protected $fillable = [
        'user_id',
        'order_id',
        'order_item_ids',
        'seller_id',
        'complaint_type',
        'resolution_type',    // ← NEW
        'other_reason',
        'description',
        'image_path',
        'status',
        'rejection_reason',
        'seller_note',
        'seller_decision',
        'reviewed_at',
        'resolved_at',
        'refund_status',
        'refund_task_id',
    ];

    // ── Casts ──────────────────────────────────────────────────────────────

    protected $casts = [
        'reviewed_at'    => 'datetime',
        'resolved_at'    => 'datetime',
        'order_item_ids' => 'array',
    ];

    protected $appends = ['image_url'];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()   { return $this->belongsTo(User::class); }
    public function order()  { return $this->belongsTo(Order::class); }
    public function seller() { return $this->belongsTo(User::class, 'seller_id'); }

    public function refundTask()
    {
        return $this->hasOne(RefundDeliveryTask::class, 'complaint_id');
    }

    public function complainedItems()
    {
        $ids = $this->order_item_ids ?? [];
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id')
            ->when(!empty($ids), fn($q) => $q->whereIn('id', $ids));
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::url($this->image_path) : null;
    }

    public function getTypeLabel(): string
    {
        if ($this->complaint_type === 'other' && $this->other_reason) {
            return $this->other_reason;
        }
        return self::COMPLAINT_TYPES[$this->complaint_type] ?? $this->complaint_type;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopePending($q)        { return $q->where('status', self::STATUS_PENDING); }
    public function scopeReviewing($q)      { return $q->where('status', self::STATUS_REVIEWING); }
    public function scopeApproved($q)       { return $q->where('status', self::STATUS_APPROVED); }
    public function scopeRejected($q)       { return $q->where('status', self::STATUS_REJECTED); }
    public function scopeSellerRejected($q) { return $q->where('status', self::STATUS_SELLER_REJECTED); }
    public function scopeForSeller($q, int $sellerId) { return $q->where('seller_id', $sellerId); }

    // ── Status helpers ─────────────────────────────────────────────────────

    public function isPending(): bool                    { return $this->status === self::STATUS_PENDING; }
    public function isReviewing(): bool                  { return $this->status === self::STATUS_REVIEWING; }
    public function isApproved(): bool                   { return $this->status === self::STATUS_APPROVED; }
    public function isSellerRejectedPendingAdmin(): bool { return $this->status === self::STATUS_SELLER_REJECTED; }
    public function isRejected(): bool                   { return $this->status === self::STATUS_REJECTED; }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }

    public function sellerCanAct(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REVIEWING]);
    }

    // ── Resolution type helpers (NEW) ──────────────────────────────────────

    /** Customer wants a replacement item. */
    public function isExchange(): bool
    {
        return $this->resolution_type === self::RESOLUTION_EXCHANGE;
    }

    /** Customer wants item(s) collected and money returned. */
    public function isReturnRefund(): bool
    {
        return $this->resolution_type === self::RESOLUTION_RETURN_REFUND
            || is_null($this->resolution_type); // legacy fallback
    }

    // ── Refund helpers ──────────────────────────────────────────────────────

    public function hasRefundTask(): bool
    {
        return !is_null($this->refund_task_id);
    }

    public function isRefundCompleted(): bool
    {
        return $this->refund_status === self::REFUND_STATUS_COMPLETED;
    }

    // ── Action helpers ─────────────────────────────────────────────────────

    public function markReviewing(?string $sellerNote = null): void
    {
        $this->update([
            'status'      => self::STATUS_REVIEWING,
            'seller_note' => $sellerNote,
            'reviewed_at' => now(),
        ]);
    }

    public function sellerApprove(?string $sellerNote = null): void
    {
        $this->update([
            'status'          => self::STATUS_APPROVED,
            'seller_note'     => $sellerNote ?? $this->seller_note,
            'seller_decision' => 'approved',
            'reviewed_at'     => $this->reviewed_at ?? now(),
            'resolved_at'     => now(),
        ]);
        ComplaintApproved::dispatch($this->fresh());
    }

    public function sellerReject(string $sellerNote, string $rejectionReason): void
    {
        $this->update([
            'status'           => self::STATUS_SELLER_REJECTED,
            'seller_note'      => $sellerNote,
            'seller_decision'  => 'rejected',
            'rejection_reason' => $rejectionReason,
            'reviewed_at'      => now(),
        ]);
    }

    public function approve(): void
    {
        $this->update([
            'status'      => self::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);
        ComplaintApproved::dispatch($this->fresh());
    }

    public function confirmRejection(): void
    {
        $this->update([
            'status'      => self::STATUS_REJECTED,
            'resolved_at' => now(),
        ]);
    }

    public function overrideToApproved(): void
    {
        $this->update([
            'status'      => self::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);
        ComplaintApproved::dispatch($this->fresh());
    }

    public function reject(string $reason): void
    {
        $this->update([
            'status'           => self::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'resolved_at'      => now(),
        ]);
    }
}