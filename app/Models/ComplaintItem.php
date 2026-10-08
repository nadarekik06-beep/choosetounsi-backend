<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One returned line of a return (complaint): how many units, and the money
 * as the client actually paid it (after coupon / flash price), frozen when
 * the return is requested and re-checked when it is refunded.
 *
 * condition / restocked_* are set at reception: only resaleable units go back
 * to stock, once (restocked_at is the idempotency stamp).
 */
class ComplaintItem extends Model
{
    protected $fillable = [
        'complaint_id', 'order_item_id', 'quantity', 'unit_price', 'gross_amount', 'discount_amount',
        'net_amount', 'commission_amount', 'seller_amount', 'condition', 'restocked_quantity', 'restocked_at',
    ];

    protected $casts = [
        'quantity'           => 'integer',
        'restocked_quantity' => 'integer',
        'unit_price'         => 'decimal:3',
        'gross_amount'       => 'decimal:3',
        'discount_amount'    => 'decimal:3',
        'net_amount'         => 'decimal:3',
        'commission_amount'  => 'decimal:3',
        'seller_amount'      => 'decimal:3',
        'restocked_at'       => 'datetime',
    ];

    public function complaint() { return $this->belongsTo(Complaint::class); }
    public function orderItem() { return $this->belongsTo(OrderItem::class); }
}
