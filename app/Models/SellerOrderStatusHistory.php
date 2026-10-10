<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One status change of a parcel (seller_order), written only by
 * App\Services\Orders\ParcelStatus: from → to, who (changed_by), what
 * (source: admin | seller | courier | api | system), when.
 */
class SellerOrderStatusHistory extends Model
{
    protected $table = 'seller_order_status_history';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
