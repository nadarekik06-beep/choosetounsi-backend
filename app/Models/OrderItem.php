<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'price',   // kept for legacy compat
        'total',
    ];

    protected $casts = [
        'unit_price' => 'decimal:3',
        'price'      => 'decimal:3',
        'total'      => 'decimal:3',
    ];

    /* ── Accessors ── */

    /** Whichever column holds the per-unit price */
    public function getUnitPriceAttribute($value): float
    {
        return (float) ($value ?: $this->attributes['price'] ?? 0);
    }

    /** Line total */
    public function getTotalAttribute($value): float
    {
        return (float) ($value ?: $this->unit_price * $this->quantity);
    }

    /* ── Relationships ── */

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}