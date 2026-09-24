<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_number',
        'subtotal',
        'discount_amount',
        'coupon_codes',
        'total_amount',
        'shipping_fee',      // ← ADDED
        'status',
        'payment_status',
        'payment_method',
        'wilaya',
        'address',
        'phone',
        'notes',
        'woocommerce_order_id',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'coupon_codes'    => 'array',
        'total_amount'    => 'decimal:3',
        'shipping_fee'    => 'decimal:3',  // ← ADDED
    ];

    /* ── Boot: auto-generate order_number ── */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = 'CT-' . strtoupper(Str::random(8));
            }
        });
    }

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Alias kept for backward-compat with SellerDashboardController */
    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Per-seller sub-orders created at checkout time.
     * One SellerOrder row per seller involved in this checkout.
     */
    public function sellerOrders()
    {
        return $this->hasMany(SellerOrder::class);

    }
public function complaints()
{
    return $this->hasMany(\App\Models\Complaint::class);
}
    /**
     * Money breakdown shown on every order screen (customer, admin).
     *
     * Built live from the non-cancelled seller_orders so partial returns and
     * cancellations are reflected. Orders without seller_orders (pre-split
     * legacy rows) fall back to the stored columns.
     *
     *   total = subtotal − discount_amount + shipping_fee   (what the customer pays)
     */
    public function moneySummary(): array
    {
        $sellerOrders = $this->relationLoaded('sellerOrders') ? $this->sellerOrders : $this->sellerOrders()->get();
        $shipping     = round((float) ($this->shipping_fee ?? 0), 3);

        if ($sellerOrders->isEmpty()) {
            $total    = round((float) $this->total_amount, 3);
            $discount = round((float) ($this->discount_amount ?? 0), 3);
            return [
                'subtotal'        => $this->subtotal !== null ? round((float) $this->subtotal, 3) : max(0, round($total - $shipping + $discount, 3)),
                'discount_amount' => $discount,
                'coupon_codes'    => $this->coupon_codes ?? [],
                'shipping_fee'    => $shipping,
                'total'           => $total,
            ];
        }

        $active   = $sellerOrders->where('status', '!=', 'cancelled');
        $subtotal = round($active->sum(fn($so) => (float) $so->subtotal), 3);
        $discount = round($active->sum(fn($so) => (float) ($so->discount_amount ?? 0)), 3);

        return [
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'coupon_codes'    => $active->pluck('coupon_code')->filter()->unique()->values()->all(),
            'shipping_fee'    => $shipping,
            'total'           => round($subtotal - $discount + $shipping, 3),
        ];
    }

    /* ── Scopes ── */

    public function scopeCompleted($query)  { return $query->where('status', 'completed'); }
    public function scopePending($query)    { return $query->where('status', 'pending'); }
    public function scopeDelivered($query)  { return $query->where('status', 'delivered'); }
    public function scopePaid($query)       { return $query->where('payment_status', 'paid'); }

    public function scopeFromPeriod($query, $startDate, $endDate = null)
    {
        $query->whereDate('created_at', '>=', $startDate);
        if ($endDate) $query->whereDate('created_at', '<=', $endDate);
        return $query;
    }
}