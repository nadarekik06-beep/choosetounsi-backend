<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Orders\OrderItemSnapshot;
use App\Services\ProductImages;
use Illuminate\Support\Facades\Storage;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'seller_order_id',
        'product_id',
        'variant_id',
        'variant_label',
        'variant_attributes', // purchase snapshot — see App\Services\Orders\OrderItemSnapshot
        'product_name',
        'quantity',
        'unit_price',
        'price',
        'total',
        'discount_amount',   // share of the seller's coupon discount on this line
        'net_total',         // total − discount_amount (commission base)
        'promotion_id',      // promotion that priced this line at checkout
        'flash_reserved',    // units held in that flash sale's quota (0 once released)
        'image_url',          // purchase snapshot: copy of the bought variant's image
        'image_source',
        // ── Commission columns (populated at checkout) ─────────────────────
        'commission_percentage',
        'commission_source',
        'commission_amount',
        'seller_amount',
        'plan_used',
    ];

    protected $casts = [
        'quantity'              => 'integer',
        'variant_attributes'    => 'array',
        'discount_amount'       => 'decimal:3',
        'net_total'             => 'decimal:3',
        'commission_percentage' => 'decimal:2',
        'commission_amount'     => 'decimal:3',
        'seller_amount'         => 'decimal:3',
    ];

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
        return round($u * $q, 3);
    }

    /**
     * The image to show for this line — see OrderItemSnapshot for the order:
     *   0. Controller pre-resolved value (setAttribute('resolved_image_url', …))
     *   1. displayImageUrl(), with the live lookup only when product is loaded
     *      (serializing a list must not lazy-load a product per row)
     */
    public function getResolvedImageUrlAttribute(): ?string
    {
        if (array_key_exists('resolved_image_url', $this->attributes)) {
            return $this->attributes['resolved_image_url'];
        }
        return $this->displayImageUrl($this->relationLoaded('product'));
    }

    /**
     * Snapshot taken at checkout → live image of the bought variant's color →
     * the product's color-less main image → null (the UI shows a placeholder).
     * Never another variant's image.
     */
    public function displayImageUrl(bool $live = true): ?string
    {
        $stored = $this->attributes['image_url'] ?? null;
        if ($stored) return str_starts_with($stored, 'http') ? $stored : url($stored);
        if (!$live || !$this->product) return null;

        [$path] = OrderItemSnapshot::pick($this->product, $this->boughtColorIds(), $this->isVariantLine());
        return $path ? url(Storage::url($path)) : null;
    }

    /** "Rouge / M" — snapshot first, then the variant as it is now. */
    public function displayVariantLabel(): ?string
    {
        return $this->attributes['variant_label'] ?? null
            ?: OrderItemSnapshot::labelOf($this->variant_attributes)
            ?: ($this->variant_id && $this->variant ? OrderItemSnapshot::labelOf(OrderItemSnapshot::attributesOf($this->variant)) : null);
    }

    /** Everything a screen needs to show what was bought, from this line only. */
    public function purchaseSnapshot(): array
    {
        return [
            'id'                 => $this->id,
            'order_id'           => $this->order_id,
            'seller_order_id'    => $this->seller_order_id,
            'product_id'         => $this->product_id,
            'variant_id'         => $this->variant_id,
            'product_name'       => $this->product_name ?? $this->product?->getAttributes()['name'] ?? null,
            'variant_label'      => $this->displayVariantLabel(),
            'variant_attributes' => $this->variant_attributes
                ?? ($this->variant_id && $this->variant ? OrderItemSnapshot::attributesOf($this->variant) : []),
            'quantity'           => (int) $this->quantity,
            'unit_price'         => (float) $this->unit_price,
            'total'              => (float) $this->total,
            'image_url'          => $this->displayImageUrl(),
        ];
    }

    /** Colors of the bought variant; [] = it had none; null = unknown (variant gone, no snapshot). */
    private function boughtColorIds(): ?array
    {
        if ($this->variant_id && $this->variant) return ProductImages::colorIdsOf($this->variant->loadMissing('attributeOptions.attribute'));
        if ($this->variant_attributes) return OrderItemSnapshot::colorIdsIn($this->variant_attributes);
        return $this->isVariantLine() ? null : [];
    }

    private function isVariantLine(): bool
    {
        return $this->variant_id || !empty($this->attributes['variant_label']) || !empty($this->variant_attributes);
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    public function product()
{
    return $this->belongsTo(Product::class, 'product_id')->withTrashed();
}

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}