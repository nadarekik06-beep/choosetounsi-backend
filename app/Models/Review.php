<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'order_item_id',
        'seller_id',
        'rating',
        'body',
        'is_anonymous',
        'is_verified_purchase',
        'status',
        'rejection_reason',
        'helpful_count',
        'not_helpful_count',
    ];

    protected $casts = [
        'rating'               => 'integer',
        'is_anonymous'         => 'boolean',
        'is_verified_purchase' => 'boolean',
        'helpful_count'        => 'integer',
        'not_helpful_count'    => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function tags()
    {
        return $this->belongsToMany(
            ReviewTag::class,
            'review_tag_pivot',
            'review_id',
            'review_tag_id'
        );
    }

    public function media()
    {
        return $this->hasMany(ReviewMedia::class)
                    ->where('is_approved', true)
                    ->whereNull('deleted_at')
                    ->orderBy('sort_order');
    }

    public function allMedia()
    {
        return $this->hasMany(ReviewMedia::class)->orderBy('sort_order');
    }

    public function reply()
    {
        return $this->hasOne(ReviewReply::class)
                    ->where('is_visible', true);
    }

    public function replies()
    {
        return $this->hasMany(ReviewReply::class);
    }

    public function votes()
    {
        return $this->hasMany(ReviewVote::class);
    }

    public function reports()
    {
        return $this->hasMany(ReviewReport::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeForSeller($query, int $sellerId)
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopeWithPhotos($query)
    {
        return $query->whereHas('media');
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified_purchase', true);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isWrittenBy(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public function recalculateVoteCounts(): void
    {
        $this->helpful_count     = $this->votes()->where('type', 'helpful')->count();
        $this->not_helpful_count = $this->votes()->where('type', 'not_helpful')->count();
        $this->saveQuietly();
    }

    /**
     * Display name respecting anonymous setting.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->is_anonymous) {
            return 'Anonymous';
        }
        // Partial masking like SHEIN: "A***a"
        $name = $this->user?->name ?? 'User';
        if (mb_strlen($name) <= 2) return $name;
        return mb_substr($name, 0, 1) . '***' . mb_substr($name, -1);
    }
}