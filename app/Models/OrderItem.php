<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items'; // explicit — avoids any table name guessing

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'price',
        'total',
    ];

    protected $casts = [
        'unit_price' => 'decimal:3',
        'price'      => 'decimal:3',
        'total'      => 'decimal:3',
        'quantity'   => 'integer',
    ];

    /* ── Accessors ── */

    public function getUnitPriceAttribute($value): float
    {
        return (float) ($value ?: ($this->attributes['price'] ?? 0));
    }

    public function getTotalAttribute($value): float
    {
        return (float) ($value ?: ($this->getUnitPriceAttribute($this->attributes['unit_price'] ?? 0) * ($this->attributes['quantity'] ?? 1)));
    }

    /* ── Relationships ── */

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The product this item refers to.
     * Uses withTrashed() so soft-deleted products still resolve
     * (the product name is also stored in product_name as a snapshot).
     */
    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }
}