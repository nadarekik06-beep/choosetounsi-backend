<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * App\Models\Complaint
 *
 * Represents a client complaint / return request on an order.
 *
 * Status lifecycle:
 *   pending → reviewing → approved
 *                      → rejected (with rejection_reason)
 *
 * @property int         $id
 * @property int         $user_id
 * @property int         $order_id
 * @property int|null    $seller_id
 * @property string      $complaint_type
 * @property string|null $other_reason
 * @property string      $description
 * @property string|null $image_path
 * @property string      $status
 * @property string|null $rejection_reason
 * @property string|null $seller_note
 * @property \Carbon\Carbon|null $reviewed_at
 * @property \Carbon\Carbon|null $resolved_at
 * @property \Carbon\Carbon      $created_at
 * @property \Carbon\Carbon      $updated_at
 */
class Complaint extends Model
{
    use HasFactory;

    // ── Constants ──────────────────────────────────────────────────────────

    const STATUS_PENDING   = 'pending';
    const STATUS_REVIEWING = 'reviewing';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVIEWING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    const COMPLAINT_TYPES = [
        'wrong_product'  => 'Wrong product received',
        'wrong_size'     => 'Wrong size',
        'wrong_color'    => 'Wrong color',
        'damaged_product'=> 'Damaged / defective product',
        'other'          => 'Other',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Full URL for the proof image, or null if none uploaded.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? Storage::url($this->image_path)
            : null;
    }

    /**
     * Human-readable label for the complaint type.
     */
    public function getTypeLabel(): string
    {
        if ($this->complaint_type === 'other' && $this->other_reason) {
            return $this->other_reason;
        }
        return self::COMPLAINT_TYPES[$this->complaint_type] ?? $this->complaint_type;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopePending($q)   { return $q->where('status', self::STATUS_PENDING); }
    public function scopeReviewing($q) { return $q->where('status', self::STATUS_REVIEWING); }
    public function scopeApproved($q)  { return $q->where('status', self::STATUS_APPROVED); }
    public function scopeRejected($q)  { return $q->where('status', self::STATUS_REJECTED); }

    public function scopeForSeller($q, int $sellerId)
    {
        return $q->where('seller_id', $sellerId);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isReviewing(): bool { return $this->status === self::STATUS_REVIEWING; }
    public function isApproved(): bool  { return $this->status === self::STATUS_APPROVED; }
    public function isRejected(): bool  { return $this->status === self::STATUS_REJECTED; }
    public function isResolved(): bool  { return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED]); }

    /**
     * Mark as reviewing (seller acknowledges).
     */
    public function markReviewing(?string $sellerNote = null): void
    {
        $this->update([
            'status'      => self::STATUS_REVIEWING,
            'seller_note' => $sellerNote,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Approve (admin decision).
     */
    public function approve(): void
    {
        $this->update([
            'status'      => self::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Reject (admin decision with mandatory reason).
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