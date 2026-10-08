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
 * @property float  $subtotal         items total BEFORE the seller's coupon
 * @property float  $discount_amount  seller-funded coupon discount (0 when none)
 */
class SellerOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'seller_id',
        'status',
        'return_status',   // null | partial | full (returns refunded)
        'payment_status',
        'subtotal',
        'coupon_id',
        'coupon_code',
        'coupon_type',
        'coupon_value',
        'discount_amount',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:3',
        'coupon_value'    => 'decimal:3',
        'discount_amount' => 'decimal:3',
    ];

    protected $appends = ['display_status'];

    /**
     * Status to show: a fully returned seller order is 'refunded' ("Returned
     * (Refunded)"); a partial return keeps the sale status (so revenue still
     * counts the kept items) and shows as 'partially_returned'.
     */
    public function getDisplayStatusAttribute(): ?string
    {
        $status = $this->attributes['status'] ?? null;
        if (($this->attributes['return_status'] ?? null) === 'partial' && !in_array($status, ['cancelled', 'refunded'], true)) {
            return 'partially_returned';
        }
        return $status;
    }

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

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopePending($q)    { return $q->where('status', 'pending'); }
    public function scopeCompleted($q)  { return $q->where('status', 'completed'); }
    public function scopeDelivered($q)  { return $q->where('status', 'delivered'); }
    public function scopeCancelled($q)  { return $q->where('status', 'cancelled'); }
    public function scopePaid($q)       { return $q->where('payment_status', 'paid'); }
    public function deliveryAssignment()
    {
        return $this->hasOne(DeliveryAssignment::class, 'seller_order_id');
    }
}