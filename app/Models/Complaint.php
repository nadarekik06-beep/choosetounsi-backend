<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Events\ComplaintApproved; // ← NEW

/**
 * FILE: app/Models/Complaint.php  ← REPLACE existing file
 *
 * Changes from v2 (refund delivery extension):
 *   - Added refund_status, refund_task_id to $fillable and $casts
 *   - Added REFUND_STATUS_* constants
 *   - Added refundTask() relationship
 *   - Added hasRefundTask() helper
 *   - sellerApprove()       now fires ComplaintApproved event
 *   - approve()             now fires ComplaintApproved event
 *   - overrideToApproved()  now fires ComplaintApproved event
 *
 * ALL existing methods and constants are preserved unchanged.
 */
class Complaint extends Model
{
    use HasFactory;

    // ── Constants ──────────────────────────────────────────────────────────

    const STATUS_PENDING                    = 'pending';
    const STATUS_REVIEWING                  = 'reviewing';
    const STATUS_APPROVED                   = 'approved';
    const STATUS_SELLER_REJECTED            = 'seller_rejected_pending_admin';
    const STATUS_REJECTED                   = 'rejected';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVIEWING,
        self::STATUS_APPROVED,
        self::STATUS_SELLER_REJECTED,
        self::STATUS_REJECTED,
    ];

    // ── Refund status constants (NEW) ──────────────────────────────────────

    const REFUND_STATUS_PENDING   = 'pending';
    const REFUND_STATUS_ASSIGNED  = 'assigned';
    const REFUND_STATUS_PICKED_UP = 'picked_up';
    const REFUND_STATUS_COMPLETED = 'completed';

    const COMPLAINT_TYPES = [
        'wrong_product'   => 'Wrong product received',
        'wrong_size'      => 'Wrong size',
        'wrong_color'     => 'Wrong color',
        'damaged_product' => 'Damaged / defective product',
        'other'           => 'Other',
    ];

    const COMPLAINT_WINDOW_DAYS = 14;

    // ── Fillable ───────────────────────────────────────────────────────────

    protected $fillable = [
        'user_id',
        'order_id',
        'seller_id',
        'complaint_type',
        'other_reason',
        'description',
        'image_path',
        'status',
        'rejection_reason',
        'seller_note',
        'seller_decision',
        'reviewed_at',
        'resolved_at',
        // ── Refund tracking (NEW) ─────────────────────────────────────────
        'refund_status',
        'refund_task_id',
    ];

    // ── Casts ──────────────────────────────────────────────────────────────

    protected $casts = [
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $appends = ['image_url'];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()    { return $this->belongsTo(User::class); }
    public function order()   { return $this->belongsTo(Order::class); }
    public function seller()  { return $this->belongsTo(User::class, 'seller_id'); }

    /**
     * The refund delivery task created when this complaint is approved.
     * NEW relationship.
     */
    public function refundTask()
    {
        return $this->hasOne(RefundDeliveryTask::class, 'complaint_id');
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

    public function scopePending($q)         { return $q->where('status', self::STATUS_PENDING); }
    public function scopeReviewing($q)       { return $q->where('status', self::STATUS_REVIEWING); }
    public function scopeApproved($q)        { return $q->where('status', self::STATUS_APPROVED); }
    public function scopeRejected($q)        { return $q->where('status', self::STATUS_REJECTED); }
    public function scopeSellerRejected($q)  { return $q->where('status', self::STATUS_SELLER_REJECTED); }
    public function scopeForSeller($q, int $sellerId) { return $q->where('seller_id', $sellerId); }

    // ── Status helpers ─────────────────────────────────────────────────────

    public function isPending(): bool                   { return $this->status === self::STATUS_PENDING; }
    public function isReviewing(): bool                 { return $this->status === self::STATUS_REVIEWING; }
    public function isApproved(): bool                  { return $this->status === self::STATUS_APPROVED; }
    public function isSellerRejectedPendingAdmin(): bool { return $this->status === self::STATUS_SELLER_REJECTED; }
    public function isRejected(): bool                  { return $this->status === self::STATUS_REJECTED; }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }

    public function sellerCanAct(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REVIEWING]);
    }

    // ── Refund helpers (NEW) ────────────────────────────────────────────────

    /**
     * Whether a refund delivery task has been created for this complaint.
     */
    public function hasRefundTask(): bool
    {
        return !is_null($this->refund_task_id);
    }

    /**
     * Whether the refund process is complete.
     */
    public function isRefundCompleted(): bool
    {
        return $this->refund_status === self::REFUND_STATUS_COMPLETED;
    }

    // ── Action helpers ─────────────────────────────────────────────────────

    /** Seller adds note → reviewing */
    public function markReviewing(?string $sellerNote = null): void
    {
        $this->update([
            'status'      => self::STATUS_REVIEWING,
            'seller_note' => $sellerNote,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Seller approves → APPROVED directly (no admin needed).
     *
     * CHANGE from v2: fires ComplaintApproved event after saving.
     * The CreateRefundDeliveryTask listener will pick it up.
     */
    public function sellerApprove(?string $sellerNote = null): void
    {
        $this->update([
            'status'          => self::STATUS_APPROVED,
            'seller_note'     => $sellerNote ?? $this->seller_note,
            'seller_decision' => 'approved',
            'reviewed_at'     => $this->reviewed_at ?? now(),
            'resolved_at'     => now(),
        ]);

        // ← NEW: trigger refund task creation
        ComplaintApproved::dispatch($this->fresh());
    }

    /**
     * Seller rejects → SELLER_REJECTED_PENDING_ADMIN.
     * No change — does NOT fire ComplaintApproved.
     */
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

    /**
     * Admin approves (final — from any non-resolved status).
     *
     * CHANGE from v2: fires ComplaintApproved event after saving.
     */
    public function approve(): void
    {
        $this->update([
            'status'      => self::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);

        // ← NEW: trigger refund task creation
        ComplaintApproved::dispatch($this->fresh());
    }

    /**
     * Admin confirms seller rejection → REJECTED (final).
     * No change — complaint rejected, no refund needed.
     */
    public function confirmRejection(): void
    {
        $this->update([
            'status'      => self::STATUS_REJECTED,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Admin overrides seller rejection → APPROVED (final).
     *
     * CHANGE from v2: fires ComplaintApproved event after saving.
     */
    public function overrideToApproved(): void
    {
        $this->update([
            'status'      => self::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);

        // ← NEW: trigger refund task creation
        ComplaintApproved::dispatch($this->fresh());
    }

    /**
     * Admin rejects (from any non-resolved status, with reason).
     * No change — no refund needed.
     */
    public function reject(string $reason): void
    {
        $this->update([
            'status'           => self::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'resolved_at'      => now(),
        ]);
    }
}