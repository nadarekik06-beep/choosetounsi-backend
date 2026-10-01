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
        'shipping_cost',     // agency cost, frozen at checkout
        'shipping_paid_by',  // customer | seller | platform
        'status',
        'payment_status',
        'payment_method',
        'wilaya',
        'address',
        'phone',
        'notes',
        // Shipping address snapshot (2026-10): NULL on legacy orders
        'recipient_name',
        'phone_secondary',
        'delegation',
        'postal_code',
        'woocommerce_order_id',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'coupon_codes'    => 'array',
        'total_amount'    => 'decimal:3',
        'shipping_fee'    => 'decimal:3',  // ← ADDED
        'shipping_cost'   => 'decimal:3',
    ];

    // Internal cost split — the customer only ever sees shipping_fee.
    protected $hidden = ['shipping_cost', 'shipping_paid_by'];

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

    /** Delivery documents generated for this order (admin audit trail). */
    public function exports()
    {
        return $this->hasMany(OrderExport::class);
    }

    /**
     * Orders placed before structured addresses (2026-10) only have the free
     * text wilaya / address / phone — no recipient, delegation or postal code.
     */
    public function hasStructuredAddress(): bool
    {
        return filled($this->recipient_name) && filled($this->postal_code);
    }

    /** Enough for a courier to find the buyer: someone to call and a place. */
    public function hasDeliverableAddress(): bool
    {
        return filled($this->phone) && filled($this->address) && filled($this->wilaya);
    }

    /** Recipient name, falling back to the account name on legacy orders. */
    public function recipientName(): ?string
    {
        return $this->recipient_name ?: $this->user?->name;
    }

    /** "12 rue X, El Menzah, 1004 Tunis" — one line, empty parts skipped. */
    public function formattedShippingAddress(): string
    {
        $city = trim(implode(' ', array_filter([$this->postal_code, $this->wilaya])));
        return implode(', ', array_filter([$this->address, $this->delegation, $city], 'filled'));
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