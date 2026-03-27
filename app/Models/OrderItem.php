<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'variant_label',
        'product_name',
        'quantity',
        'unit_price',
        'price',
        'total',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    /* ── Accessors ── */

    public function getUnitPriceAttribute($value): float
    {
        return (float) ($value ?: ($this->attributes['price'] ?? 0));
    }

    public function getPriceAttribute($value): float
    {
        return (float) ($value ?: ($this->attributes['unit_price'] ?? 0));
    }

    public function getTotalAttribute($value): float
    {
        if ($value) return (float) $value;
        $unitPrice = (float) ($this->attributes['unit_price'] ?? $this->attributes['price'] ?? 0);
        $quantity  = (int)   ($this->attributes['quantity'] ?? 1);
        return round($unitPrice * $quantity, 2);
    }

    /* ── Relationships ── */

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function variant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}