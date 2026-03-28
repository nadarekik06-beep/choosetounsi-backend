<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * FILE: app/Models/Complaint.php  ← REPLACE existing file
 *
 * Changes from v1:
 *   - Added STATUS_SELLER_REJECTED constant
 *   - Added isSellerRejectedPendingAdmin() helper
 *   - Added sellerApprove() helper
 *   - Added sellerReject() helper
 *   - Updated VALID_STATUSES array
 *   - Updated isResolved() to include seller_rejected_pending_admin as non-final
 */
class Complaint extends Model
{
    use HasFactory;

    // ── Constants ──────────────────────────────────────────────────────────

    const STATUS_PENDING                    = 'pending';
    const STATUS_REVIEWING                  = 'reviewing';
    const STATUS_APPROVED                   = 'approved';
    const STATUS_SELLER_REJECTED            = 'seller_rejected_pending_admin'; // NEW
    const STATUS_REJECTED                   = 'rejected';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVIEWING,
        self::STATUS_APPROVED,
        self::STATUS_SELLER_REJECTED,
        self::STATUS_REJECTED,
    ];

    const COMPLAINT_TYPES = [
        'wrong_product'   => 'Wrong product received',
        'wrong_size'      => 'Wrong size',
        'wrong_color'     => 'Wrong color',
        'damaged_product' => 'Damaged / defective product',
        'other'           => 'Other',
    ];

    /** Maximum days after delivery the client can file a complaint. */
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
        'seller_decision',   // NEW: 'approved' | 'rejected'
        'reviewed_at',
        'resolved_at',
    ];

    // ── Casts ──────────────────────────────────────────────────────────────

    protected $casts = [
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $appends = ['image_url'];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()   { return $this->belongsTo(User::class); }
    public function order()  { return $this->belongsTo(Order::class); }
    public function seller() { return $this->belongsTo(User::class, 'seller_id'); }

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

    public function scopePending($q)          { return $q->where('status', self::STATUS_PENDING); }
    public function scopeReviewing($q)        { return $q->where('status', self::STATUS_REVIEWING); }
    public function scopeApproved($q)         { return $q->where('status', self::STATUS_APPROVED); }
    public function scopeRejected($q)         { return $q->where('status', self::STATUS_REJECTED); }
    public function scopeSellerRejected($q)   { return $q->where('status', self::STATUS_SELLER_REJECTED); }
    public function scopeForSeller($q, int $sellerId) { return $q->where('seller_id', $sellerId); }

    // ── Status helpers ─────────────────────────────────────────────────────

    public function isPending(): bool                  { return $this->status === self::STATUS_PENDING; }
    public function isReviewing(): bool                { return $this->status === self::STATUS_REVIEWING; }
    public function isApproved(): bool                 { return $this->status === self::STATUS_APPROVED; }
    public function isSellerRejectedPendingAdmin(): bool { return $this->status === self::STATUS_SELLER_REJECTED; }
    public function isRejected(): bool                 { return $this->status === self::STATUS_REJECTED; }

    /**
     * A complaint is "resolved" only when admin has made the FINAL decision.
     * seller_rejected_pending_admin is NOT resolved — admin still needs to act.
     */
    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }

    /**
     * Can the seller still act on this complaint?
     * Seller can act when status is pending or reviewing.
     */
    public function sellerCanAct(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REVIEWING]);
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

    /** Seller approves → APPROVED directly (no admin needed) */
    public function sellerApprove(?string $sellerNote = null): void
    {
        $this->update([
            'status'          => self::STATUS_APPROVED,
            'seller_note'     => $sellerNote ?? $this->seller_note,
            'seller_decision' => 'approved',
            'reviewed_at'     => $this->reviewed_at ?? now(),
            'resolved_at'     => now(),
        ]);
    }

    /** Seller rejects → SELLER_REJECTED_PENDING_ADMIN (admin must validate) */
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

    /** Admin approves (final) */
    public function approve(): void
    {
        $this->update([
            'status'      => self::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);
    }

    /** Admin confirms seller rejection → REJECTED (final) */
    public function confirmRejection(): void
    {
        $this->update([
            'status'      => self::STATUS_REJECTED,
            'resolved_at' => now(),
        ]);
    }

    /** Admin overrides seller rejection → APPROVED (final) */
    public function overrideToApproved(): void
    {
        $this->update([
            'status'      => self::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);
    }

    /** Admin rejects (from any non-resolved status, with reason) */
    public function reject(string $reason): void
    {
        $this->update([
            'status'           => self::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'resolved_at'      => now(),
        ]);
    }
}