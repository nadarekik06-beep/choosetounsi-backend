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
        'total_amount' => 'decimal:3',
        'shipping_fee' => 'decimal:3',  // ← ADDED
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