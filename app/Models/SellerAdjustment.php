<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A correction on a seller's payouts that never rewrites history: a negative
 * amount is owed by the seller (e.g. a refunded return on a sale that was
 * already paid out, or the return shipping when the seller was at fault).
 *
 * Pending (settlement_batch_id null) until the next settlement batch picks it
 * up; applied_at is set when that batch is paid.
 */
class SellerAdjustment extends Model
{
    public const TYPE_RETURN_DEBIT    = 'return_debit';
    public const TYPE_RETURN_SHIPPING = 'return_shipping';
    /** Agency fee of a parcel the client refused at the door, when the admin setting bills the seller. */
    public const TYPE_REFUSED_PARCEL  = 'refused_parcel';
    /** Free-delivery contribution still owed on a fully returned parcel (the delivery was made). */
    public const TYPE_FREE_DELIVERY   = 'free_delivery_contribution';

    protected $fillable = [
        'seller_id', 'seller_order_id', 'complaint_id', 'type', 'amount', 'description',
        'created_by', 'settlement_batch_id', 'applied_at',
    ];

    protected $casts = ['amount' => 'decimal:3', 'applied_at' => 'datetime'];

    public function seller()      { return $this->belongsTo(User::class, 'seller_id'); }
    public function sellerOrder() { return $this->belongsTo(SellerOrder::class); }
    public function complaint()   { return $this->belongsTo(Complaint::class); }
    public function creator()     { return $this->belongsTo(User::class, 'created_by'); }

    public function scopePending($q) { return $q->whereNull('settlement_batch_id'); }
}
