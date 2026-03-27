<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id', 'product_id', 'variant_id', 'variant_label',
        'product_name', 'quantity', 'unit_price', 'price', 'total',
    ];

    protected $casts = ['quantity' => 'integer'];

    public function getUnitPriceAttribute($value): float { return (float) ($value ?: ($this->attributes['price'] ?? 0)); }

    public function getPriceAttribute($value): float { return (float) ($value ?: ($this->attributes['unit_price'] ?? 0)); }

    public function getTotalAttribute($value): float { if ($value) return (float) $value; $u = (float) ($this->attributes['unit_price'] ?? 0); $q = (int) ($this->attributes['quantity'] ?? 1); return round($u * $q, 2); }

    public function order() { return $this->belongsTo(Order::class); }

    public function product() { return $this->belongsTo(Product::class, 'product_id')->withTrashed(); }

    public function variant() { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}
