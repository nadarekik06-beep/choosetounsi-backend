<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInteraction extends Model
{
    const UPDATED_AT = null;

    const EVENTS = [
        'view', 'click', 'cart_add', 'favorite_add', 'favorite_remove',
        'purchase', 'search', 'follow', 'unfollow',
    ];

    protected $fillable = [
        'user_id', 'session_id', 'product_id', 'seller_id', 'category_id',
        'event_type', 'source_section', 'search_query', 'order_id', 'created_at',
        'traffic_source', 'device', 'funnel_excluded',
    ];

    protected $casts = [
        'user_id'     => 'integer',
        'product_id'  => 'integer',
        'seller_id'   => 'integer',
        'category_id' => 'integer',
        'order_id'    => 'integer',
        'created_at'  => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
