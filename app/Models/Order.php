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
        'shipping_paid_by',  // customer | seller | mixed (legacy: platform)
        'shipping_per_parcel', // true: delivery charged per parcel (seller_orders.delivery_fee)
        'status',
        'return_status',   // null | partial | full (returns refunded)
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
        'shipping_per_parcel' => 'boolean',
    ];

    // Internal cost split — the customer only ever sees shipping_fee.
    protected $hidden = ['shipping_cost', 'shipping_paid_by'];

    protected $appends = ['display_status'];

    /**
     * Status to show: a fully returned order is 'refunded' ("Returned
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
        // Postal code is optional, so it can't mark an order as structured.
        return filled($this->recipient_name) && filled($this->delegation);
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
     * Built live from the parcels still owed (not cancelled, not refused) so
     * partial returns, cancellations and refusals are reflected. Orders without
     * seller_orders (pre-split legacy rows) fall back to the stored columns.
     *
     *   total = subtotal − discount_amount + shipping_fee   (what the customer pays)
     *
     * Delivery is charged per parcel (shipping_per_parcel): only live parcels'
     * fees count. Legacy orders charged it once per order, kept while at least
     * one sub-order is live. An order with nothing live owes nothing: every
     * figure is 0 and 'original' keeps the stored checkout amounts (never
     * overwritten) for the struck-through history line.
     */
    public function moneySummary(): array
    {
        $sellerOrders = $this->relationLoaded('sellerOrders') ? $this->sellerOrders : $this->sellerOrders()->get();
        $original     = $this->originalAmounts();

        $active    = $sellerOrders->whereNotIn('status', SellerOrder::NOT_SHIPPED);
        $cancelled = in_array($this->status, SellerOrder::NOT_SHIPPED, true) || ($sellerOrders->isNotEmpty() && $active->isEmpty());

        if ($cancelled) {
            return [
                'subtotal'        => 0.0,
                'discount_amount' => 0.0,
                'coupon_codes'    => [],
                'shipping_fee'    => 0.0,
                'total'           => 0.0,
                'is_cancelled'    => true,
                'original'        => $original,
            ];
        }

        if ($sellerOrders->isEmpty()) {
            return $original + ['coupon_codes' => $this->coupon_codes ?? [], 'is_cancelled' => false, 'original' => $original];
        }

        $m        = fn ($v) => \App\Support\Millimes::of($v ?? 0);
        $subtotal = $active->sum(fn ($so) => $m($so->subtotal));
        $discount = $active->sum(fn ($so) => $m($so->discount_amount));
        $shipping = $this->shipping_per_parcel
            ? $active->sum(fn ($so) => $m($so->getAttribute('delivery_fee')))
            : $m($this->shipping_fee);
        $f = fn (int $v) => \App\Support\Millimes::toFloat($v);

        return [
            'subtotal'        => $f($subtotal),
            'discount_amount' => $f($discount),
            'coupon_codes'    => $active->pluck('coupon_code')->filter()->unique()->values()->all(),
            'shipping_fee'    => $f($shipping),
            'total'           => $f($subtotal - $discount + $shipping),
            'is_cancelled'    => false,
            'original'        => $original,
        ];
    }

    /** Amounts frozen at checkout — kept as history, cancelled or not. */
    public function originalAmounts(): array
    {
        $shipping = round((float) ($this->shipping_fee ?? 0), 3);
        $total    = round((float) $this->total_amount, 3);
        $discount = round((float) ($this->discount_amount ?? 0), 3);

        return [
            'subtotal'        => $this->subtotal !== null ? round((float) $this->subtotal, 3) : max(0, round($total - $shipping + $discount, 3)),
            'discount_amount' => $discount,
            'shipping_fee'    => $shipping,
            'total'           => $total,
        ];
    }

    /**
     * Copy the live money onto the order for an API payload, plus
     * is_cancelled / amount_due / original_amounts for the display.
     * Call AFTER reading anything that needs the stored columns.
     */
    public function applyMoneySummary(?array $money = null): array
    {
        $money ??= $this->moneySummary();
        $this->subtotal        = $money['subtotal'];
        $this->discount_amount = $money['discount_amount'];
        $this->coupon_codes    = $money['coupon_codes'];
        $this->shipping_fee    = $money['shipping_fee'];
        $this->total_amount    = $money['total'];
        $this->setAttribute('is_cancelled', $money['is_cancelled']);
        $this->setAttribute('amount_due', $money['total']);
        $this->setAttribute('original_amounts', $money['original']);
        return $money;
    }

    /**
     * orders.status from its parcels (seller_orders): every parcel delivered
     * (or done: cancelled / refused / returned / refunded) → delivered; all
     * refused (back at the sellers) → refused (returned_to_seller); all
     * cancelled → cancelled; else the most advanced live step.
     * Legacy 'completed' counts as delivered.
     */
    public static function syncStatusFromParcels(int $orderId): void
    {
        $statuses = SellerOrder::where('order_id', $orderId)->pluck('status')->all();
        if (!$statuses) return;

        $set   = array_values(array_unique($statuses));
        $live  = array_values(array_diff($set, SellerOrder::NOT_SHIPPED));
        $only  = fn (array $allowed) => !array_diff($set, $allowed);

        $derived = match (true) {
            $set === ['cancelled']                                   => 'cancelled',
            $only(['cancelled', 'returned_to_seller'])               => 'returned_to_seller',
            $only(SellerOrder::NOT_SHIPPED)                          => 'refused',
            $only(['refunded', ...SellerOrder::NOT_SHIPPED])         => 'refunded',
            $only(['delivered', 'completed', 'refunded', ...SellerOrder::NOT_SHIPPED])
                && array_intersect($live, ['delivered', 'completed']) => 'delivered',
            in_array('out_for_delivery', $live, true)               => 'out_for_delivery',
            in_array('handed_to_courier', $live, true)              => 'handed_to_courier',
            in_array('confirmed', $live, true)
                || array_intersect($live, ['delivered', 'completed']) => 'confirmed',
            default                                                  => 'pending',
        };

        static::whereKey($orderId)->update(['status' => $derived]);
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