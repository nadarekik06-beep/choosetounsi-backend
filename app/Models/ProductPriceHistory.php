<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPriceHistory extends Model
{
    protected $table = 'product_price_history';

    protected $fillable = ['product_id', 'variant_id', 'price', 'source', 'changed_at'];

    protected $casts = [
        'price'      => 'decimal:3',
        'changed_at' => 'datetime',
    ];
}
