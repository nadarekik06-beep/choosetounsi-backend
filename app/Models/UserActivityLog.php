<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivityLog extends Model
{
    // Append-only table — no updated_at
    const UPDATED_AT = null;

    protected $table = 'user_activity_logs';

    protected $fillable = [
        'user_id',
        'product_id',
        'category_id',
        'action',
        'session_id',
    ];

    protected $casts = [
        'user_id'     => 'integer',
        'product_id'  => 'integer',
        'category_id' => 'integer',
        'created_at'  => 'datetime',
    ];

    // Valid action types — used for validation throughout the codebase
    const ACTION_VIEW     = 'view';
    const ACTION_FAVORITE = 'favorite';
    const ACTION_CART     = 'cart';
    const ACTION_ORDER    = 'order';
    const ACTION_PURCHASE = 'purchase'; // ← ADDED

    const ACTIONS = [
        self::ACTION_VIEW,
        self::ACTION_FAVORITE,
        self::ACTION_CART,
        self::ACTION_ORDER,
        self::ACTION_PURCHASE, // ← ADDED
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

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}