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
        'order_id',
        'seller_order_id',   // ← links item to its seller's sub-order
        'product_id',
        'variant_id',
        'variant_label',
        'product_name',
        'quantity',
        'unit_price',
        'price',
        'total',
        'image_url',         // optional snapshot stored at order time
    ];

    protected $casts = ['quantity' => 'integer'];

    /**
     * Append resolved_image_url to toArray() / JSON output so the controller's
     * setAttribute() call is always serialized correctly.
     */
    protected $appends = ['resolved_image_url'];

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
        $q = (int)   ($this->attributes['quantity']   ?? 1);
        return round($u * $q, 2);
    }

    /**
     * Resolve the best image URL for this order item.
     *
     * Priority:
     *   0. Controller pre-resolved value (set via setAttribute in ClientOrderApiController)
     *      — this always wins over the stale stored snapshot.
     *   1. Stored snapshot (image_url column set at checkout time)
     *      — kept as fallback for items not going through the controller.
     *   2. Variant's first image (live lookup — only if relations are loaded)
     *   3. Product primary image
     *   4. null
     *
     * WHY THE array_key_exists CHECK:
     * ClientOrderApiController calls $item->setAttribute('resolved_image_url', ...)
     * which puts the variant-aware URL into $this->attributes[].
     * Without this check, toArray() would call this accessor which previously
     * read image_url (the wrong product-level snapshot) and returned too early,
     * overwriting the correct value set by the controller.
     */
    public function getResolvedImageUrlAttribute(): ?string
    {
        // 0. Controller pre-resolved — trust it unconditionally
        if (array_key_exists('resolved_image_url', $this->attributes)) {
            return $this->attributes['resolved_image_url'];
        }

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

    /**
     * The seller's sub-order this item belongs to.
     * Null for items created before the seller_orders migration.
     */
    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}