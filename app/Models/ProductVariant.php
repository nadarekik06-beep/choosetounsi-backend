<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'price_override',
        'stock',
        'is_active',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'price_override' => 'decimal:3',
        'stock'          => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The attribute options that define this variant combination.
     * e.g. [Red (color), M (size)]
     */
    public function attributeOptions()
    {
        return $this->belongsToMany(
            AttributeOption::class,
            'variant_attribute_values',
            'variant_id',
            'attribute_option_id'
        )->with('attribute:id,slug,name,type');
    }

    /**
     * Images directly linked to this variant.
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class, 'variant_id')->orderBy('order');
    }

    /**
     * Primary image for this variant.
     */
    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class, 'variant_id')
            ->where('is_primary', true)
            ->orderBy('order');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * Effective selling price: use override when set, otherwise product base price.
     */
    public function getEffectivePriceAttribute(): float
    {
        return $this->price_override !== null
            ? (float) $this->price_override
            : (float) $this->product->price;
    }

    /**
     * Human-readable label, e.g. "Red / M"
     */
    public function getLabelAttribute(): string
    {
        return $this->relationLoaded('attributeOptions')
            ? $this->attributeOptions->pluck('value')->join(' / ')
            : '';
    }

    /**
     * Keyed map for quick front-end lookup.
     * Returns: ['color' => ['id' => 3, 'value' => 'Red', 'color_hex' => '#FF0000'], ...]
     */
    public function getOptionMapAttribute(): array
{
    if (!$this->relationLoaded('attributeOptions')) {
        return [];
    }

    $map        = [];
    $colorGroup = [];   // accumulate all color options for this variant

    foreach ($this->attributeOptions as $opt) {
        $slug = $opt->attribute->slug;

        if ($slug === 'color') {
            $colorGroup[] = $opt;
        } else {
            $map[$slug] = [
                'id'        => $opt->id,
                'value'     => $opt->value,
                'color_hex' => $opt->color_hex,
            ];
        }
    }

    if (!empty($colorGroup)) {
        // Sort by id so the key is stable regardless of DB return order
        usort($colorGroup, fn($a, $b) => $a->id <=> $b->id);

        $map['color'] = [
            'id'        => $colorGroup[0]->id,   // primary (lowest) ID — backward compat
            'ids'       => array_map(fn($o) => $o->id, $colorGroup),
            'value'     => implode('+', array_map(fn($o) => $o->value, $colorGroup)),
            'color_hex' => $colorGroup[0]->color_hex,
        ];
    }

    return $map;
}

    /**
     * Resolved image URLs for this variant.
     * Falls back to product-level images if variant has none.
     */
    public function getImageUrlsAttribute(): array
    {
        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            return $this->images->map(fn($img) => Storage::url($img->image_path))->toArray();
        }
        return [];
    }

    /**
     * The color option_id for this variant (used to group images by color).
     */
    public function getColorOptionIdAttribute(): ?int
    {
        if (!$this->relationLoaded('attributeOptions')) return null;
        $colorOpt = $this->attributeOptions->first(
            fn($opt) => $opt->attribute->slug === 'color'
        );
        return $colorOpt?->id;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->is_active && $this->stock > 0;
    }
}