<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id', 'order_id', 'seller_order_id', 'user_id', 'discount_amount',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:3',
    ];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
