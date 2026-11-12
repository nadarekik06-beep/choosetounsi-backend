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
        'returned_quantity', // units refunded through a return (quantity is what is kept)
        'returned_amount',   // what the client paid for those units
        'returned_at',
        'unit_price',
        'price',
        'total',
        'discount_amount',   // share of the seller's coupon discount on this line
        'net_total',         // total − discount_amount (commission base)
        'promotion_id',      // promotion that priced this line at checkout
        'flash_reserved',    // units held in that flash sale's quota (0 once released)
        'promotion_source',      // seller | admin, frozen at checkout
        'original_unit_price',   // unit price before the promotion
        'promo_discount_amount', // (original_unit_price - unit_price) x quantity
        // ── Discount snapshot (AUDIT_DISCOUNTS C-1 / M-4 / M-7 / M-8) ─────────
        'discount_type',           // promotion | flash_sale | pack | coupon | none
        'discount_source_id',      // promotions.id / packs.id / coupons.id
        'applied_discount_amount', // money that discount took off this line
        'promo_discount_type',     // percentage | fixed (promotion terms)
        'promo_discount_value',
        'pack_id',
        'pack_group',              // one group per pack row of the order
        'pack_allocation',         // this line's share of the pack price × pack quantity
        'pack_original_value',     // this line's items at their original price
        // ── Delivery included in the displayed price (PricingService, NULL on older orders) ──
        'parcel_size',                 // the item's size (S / L / XL)
        'included_fee',                // fee inside ONE unit's display price (null on pack lines)
        'included_fee_total',          // fee inside the whole line (pack lines: their share of the pack's fee)
        'pack_units',                  // pack lines: packs bought (a pack = one unit)
        'display_unit_price',          // unit price the client saw
        'display_original_unit_price', // crossed-out unit price the client saw
        'commission_tier_price',   // original unit price the commission bracket was chosen from
        'funded_by',               // seller | platform
        'platform_funded_amount',  // discount paid back to the seller by the platform
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
        'returned_quantity'     => 'integer',
        'returned_amount'       => 'decimal:3',
        'returned_at'           => 'datetime',
        'variant_attributes'    => 'array',
        'discount_amount'       => 'decimal:3',
        'net_total'             => 'decimal:3',
        'commission_percentage' => 'decimal:2',
        'commission_amount'     => 'decimal:3',
        'seller_amount'         => 'decimal:3',
        'applied_discount_amount' => 'decimal:3',
        'pack_allocation'       => 'decimal:3',
        'pack_original_value'   => 'decimal:3',
        'platform_funded_amount' => 'decimal:3',
    ];

    protected $appends = ['resolved_image_url', 'ordered_quantity', 'return_state'];

    /**
     * A refunded return reduces the line's live figures (quantity, total,
     * commission…) so every sales query is net; returned_quantity keeps the rest.
     */
    public function getOrderedQuantityAttribute(): int
    {
        return (int) ($this->attributes['quantity'] ?? 0) + (int) ($this->attributes['returned_quantity'] ?? 0);
    }

    /** null | partially_returned | returned */
    public function getReturnStateAttribute(): ?string
    {
        $returned = (int) ($this->attributes['returned_quantity'] ?? 0);
        if ($returned <= 0) return null;
        return (int) ($this->attributes['quantity'] ?? 0) > 0 ? 'partially_returned' : 'returned';
    }

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

    // ── What the client saw (delivery included in the price) ───────────────
    // Orders placed before delivery was included have no fee: these equal the base amounts.

    /** Delivery fee included in this line's displayed price. */
    public function includedFeeTotal(): float
    {
        return (float) ($this->attributes['included_fee_total'] ?? 0);
    }

    /** Unit price as displayed to the client. */
    public function clientUnitPrice(): float
    {
        $display = $this->attributes['display_unit_price'] ?? null;
        return round((float) ($display ?? $this->unit_price), 3);
    }

    /** Line total as displayed to the client (before the coupon). */
    public function clientTotal(): float
    {
        return round((float) $this->total + $this->includedFeeTotal(), 3);
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
            'quantity'           => $this->ordered_quantity,   // as bought
            'returned_quantity'  => (int) ($this->attributes['returned_quantity'] ?? 0),
            'return_state'       => $this->return_state,
            'unit_price'         => (float) $this->unit_price,
            // Pack lines: their exact share of the pack price (unit_price is rounded for display)
            'total'              => $this->pack_allocation !== null
                ? round((float) $this->pack_allocation, 3)
                : round((float) $this->unit_price * $this->ordered_quantity, 3),
            'discount_type'      => $this->discount_type,
            'applied_discount_amount' => round((float) ($this->applied_discount_amount ?? 0), 3),
            'pack_id'            => $this->pack_id,
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