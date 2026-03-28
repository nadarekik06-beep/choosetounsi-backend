<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id', 'product_id', 'variant_id', 'variant_label',
        'product_name', 'quantity', 'unit_price', 'price', 'total',
        'image_url',   // optional snapshot stored at order time
    ];

    protected $casts = ['quantity' => 'integer'];

    // ── Accessors ──────────────────────────────────────────────────────────

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
        $u = (float) ($this->attributes['unit_price'] ?? 0);
        $q = (int) ($this->attributes['quantity'] ?? 1);
        return round($u * $q, 2);
    }

    /**
     * Resolve the best image URL for this order item.
     * Priority: stored snapshot → variant image → product primary image
     */
    public function getResolvedImageUrlAttribute(): ?string
    {
        // 1. Stored snapshot (set at checkout)
        if (!empty($this->attributes['image_url'])) {
            $stored = $this->attributes['image_url'];
            return str_starts_with($stored, 'http') ? $stored : url($stored);
        }

        // 2. Variant's first image (live lookup — only if relations are loaded)
        if ($this->relationLoaded('variant') && $this->variant) {
            $v = $this->variant;
            if ($v->relationLoaded('images') && $v->images->isNotEmpty()) {
                return Storage::url($v->images->first()->image_path);
            }
        }

        // 3. Product primary image
        if ($this->relationLoaded('product') && $this->product) {
            $p = $this->product;
            if ($p->relationLoaded('primaryImage') && $p->primaryImage) {
                return Storage::url($p->primaryImage->image_path);
            }
        }

        return null;
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id'); // removed withTrashed() — Product does not use SoftDeletes
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}