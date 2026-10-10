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
 * @property string $status          see App\Services\Orders\ParcelStatus (the only writer)
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
        'subtotal'          => 'decimal:3',
        'coupon_value'      => 'decimal:3',
        'discount_amount'   => 'decimal:3',
        'is_free_delivery'  => 'boolean',
        'cash_collected_at'     => 'datetime',
        'refused_at'            => 'datetime',
        'returned_to_seller_at' => 'datetime',
        'carrier_status_at'     => 'datetime',
    ];

    /**
     * Parcels that are owed nothing by the client and earn nothing: cancelled
     * before the courier, or refused at the door (then returned to the seller).
     */
    public const NOT_SHIPPED = ['cancelled', 'refused', 'returned_to_seller'];

    /** Refused at the door, before or after it is back at the seller. */
    public const REFUSED = ['refused', 'returned_to_seller'];

    /** "'cancelled','refused',…" for raw SQL IN (…) lists. */
    public static function notShippedSql(): string
    {
        return "'" . implode("','", self::NOT_SHIPPED) . "'";
    }

    /** CHOOSE'Tounsi's own products: its net is platform revenue, never a seller payout. */
    public function isPlatformParcel(): bool
    {
        return $this->seller_id === null || \App\Helpers\PlatformUser::isPlatform((int) $this->seller_id);
    }

    /** SQL for "this seller_orders row is a platform parcel" (alias $a). */
    public static function platformParcelSql(string $a = 'so'): string
    {
        $id = (int) \App\Helpers\PlatformUser::id();
        return "({$a}.seller_id IS NULL OR {$a}.seller_id = {$id})";
    }

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
    public function statusHistory()
    {
        return $this->hasMany(SellerOrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function deliveryAssignment()
    {
        return $this->hasOne(DeliveryAssignment::class, 'seller_order_id');
    }

    /**
     * Payouts follow cancellation: a cancelled sub-order owes the seller
     * nothing (payout 'cancelled'); re-opened, it waits for cash again
     * ('pending'). Frozen money columns are never touched (history), and a
     * payout already paid or batched is left alone. Query-builder write, so
     * callers using DB::table and the observer share it.
     */
    public static function syncPayoutWithStatus(array $ids, bool $cancelled): void
    {
        if (!$ids) return;

        \Illuminate\Support\Facades\DB::table('seller_orders')
            ->whereIn('id', $ids)
            ->whereNull('settlement_batch_id')
            ->where('payout_status', $cancelled ? 'pending' : 'cancelled')
            ->where('status', $cancelled ? '=' : '!=', 'cancelled')
            ->update(['payout_status' => $cancelled ? 'cancelled' : 'pending', 'updated_at' => now()]);
    }
}