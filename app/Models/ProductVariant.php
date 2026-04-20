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
     *
     * FIX 1: Added ->distinct() so that if the pivot table ever returns
     * duplicate rows (e.g. due to a bad INSERT or missing UNIQUE constraint
     * enforcement at the DB level), each AttributeOption is loaded only once.
     * This eliminates the root cause of the duplicate React key `104` error —
     * which happened because the same size option appeared twice in the
     * collection when building selectable_axes on the ProductController.
     */
    public function attributeOptions()
    {
        return $this->belongsToMany(
            AttributeOption::class,
            'variant_attribute_values',
            'variant_id',
            'attribute_option_id'
        )
        ->distinct()                              // ← FIX 1
        ->with('attribute:id,slug,name,type');
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
     * Human-readable label, e.g. "Red+Blue / M"
     */
    public function getLabelAttribute(): string
    {
        $options = $this->relationLoaded('attributeOptions')
            ? $this->attributeOptions
            : $this->attributeOptions()->get();   // lazy fallback
 
        if ($options->isEmpty()) return '';
 
        // Color options first, then others sorted by attribute slug
        $colorValues    = $options
            ->filter(fn($opt) => optional($opt->attribute)->slug === 'color')
            ->map(fn($opt) => $opt->value);
 
        $nonColorValues = $options
            ->filter(fn($opt) => optional($opt->attribute)->slug !== 'color')
            ->sortBy(fn($opt) => optional($opt->attribute)->slug ?? '')
            ->map(fn($opt) => $opt->value);
 
        return $colorValues->merge($nonColorValues)->filter()->join(' / ');
    }

    /**
     * Keyed map for quick front-end lookup.
     *
     * For non-color axes:
     *   ['size' => ['id' => 104, 'value' => 'M', 'color_hex' => null]]
     *
     * For the color axis (single or multi-color group):
     *   ['color' => [
     *       'id'        => 101,              ← primary (lowest) ID
     *       'ids'       => [101, 102],        ← all IDs in the group
     *       'value'     => 'Black+Red',
     *       'color_hex' => '#000000',         ← hex of the primary color
     *   ]]
     *
     * The frontend's isOptionAvailable() uses entry.id to compare against
     * selectedOptions['color'], which stores the primary ID.
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
                // Non-color: one entry per attribute slug
                // If the same slug somehow appears twice (bad data), last write wins —
                // but distinct() on the relationship prevents this.
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
                'id'        => $colorGroup[0]->id,                          // primary (lowest) ID
                'ids'       => array_map(fn($o) => $o->id, $colorGroup),    // all IDs in group
                'value'     => implode('+', array_map(fn($o) => $o->value, $colorGroup)),
                'color_hex' => $colorGroup[0]->color_hex,
            ];
        }

        return $map;
    }

    /**
     * Resolved image URLs for this variant.
     * Falls back to empty array if no variant-specific images exist
     * (caller is responsible for the product-level fallback).
     */
    public function getImageUrlsAttribute(): array
    {
        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            return $this->images->map(fn($img) => Storage::url($img->image_path))->toArray();
        }
        return [];
    }

    /**
     * The PRIMARY color option id for this variant.
     *
     * FIX: For multi-color groups (e.g. Black+Red), returns the lowest
     * sorted color option ID — which is the same "primary" ID used in
     * option_map['color']['id'] and in saveColorImages() as the storage key.
     * This ensures all three sides (frontend selection, image storage, image
     * retrieval) agree on which ID represents the group.
     */
    public function getColorOptionIdAttribute(): ?int
    {
        if (!$this->relationLoaded('attributeOptions')) return null;

        $colorOpts = $this->attributeOptions
            ->filter(fn($opt) => $opt->attribute->slug === 'color')
            ->sortBy('id')
            ->values();

        return $colorOpts->first()?->id;  // lowest id = primary
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->is_active && $this->stock > 0;
    }
}