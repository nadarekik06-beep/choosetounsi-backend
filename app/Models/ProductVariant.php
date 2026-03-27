<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

        $map = [];
        foreach ($this->attributeOptions as $opt) {
            $map[$opt->attribute->slug] = [
                'id'        => $opt->id,
                'value'     => $opt->value,
                'color_hex' => $opt->color_hex,
            ];
        }
        return $map;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->is_active && $this->stock > 0;
    }
}