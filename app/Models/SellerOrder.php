<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SellerOrder — a per-seller sub-order created automatically at checkout.
 *
 * Relationship overview:
 *   SellerOrder belongsTo Order          (the parent checkout session)
 *   SellerOrder belongsTo User (seller)  (the seller who owns this sub-order)
 *   SellerOrder hasMany    OrderItem     (via seller_order_id)
 *
 * @property int    $id
 * @property int    $order_id
 * @property int    $seller_id
 * @property string $status          pending|processing|completed|delivered|cancelled
 * @property string $payment_status  unpaid|paid|refunded
 * @property float  $subtotal
 */
class SellerOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'seller_id',
        'status',
        'payment_status',
        'subtotal',
    ];

    protected $casts = [
        'subtotal' => 'decimal:3',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    /** The parent checkout order (customer's unified view) */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** The seller who owns this sub-order */
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** Only this seller's line items */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'seller_order_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopePending($q)    { return $q->where('status', 'pending'); }
    public function scopeCompleted($q)  { return $q->where('status', 'completed'); }
    public function scopeDelivered($q)  { return $q->where('status', 'delivered'); }
    public function scopeCancelled($q)  { return $q->where('status', 'cancelled'); }
    public function scopePaid($q)       { return $q->where('payment_status', 'paid'); }
}