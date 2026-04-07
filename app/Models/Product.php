<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id', 'category_id', 'subcategory_id',
        'name', 'slug', 'description', 'short_description',
        'price', 'stock', 'sku',
        'is_approved', 'is_active', 'featured', 'views',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'is_active'   => 'boolean',
        'featured'    => 'boolean',
        'price'       => 'decimal:3',
    ];

    protected $appends = ['primary_image_url'];

    // ── Boot ──────────────────────────────────────────────────────────────

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::deleting(function ($product) {
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image->image_path);
            }
            $product->images()->delete();
            $product->attributeValues()->delete();
            // Cascade handled by DB foreign key, but explicit for clarity
            $product->variants()->delete();
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('order');
    }

    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Raw attribute value rows (product-level, non-variant attributes).
     */
    public function attributeValues()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Attribute values with their attribute definitions & options eager-loaded.
     */
    public function attributes()
    {
        return $this->attributeValues()->with(['attribute.options']);
    }

    /**
     * All variants for this product (with their option data).
     */
    public function variants()
    {
        return $this->hasMany(ProductVariant::class)
            ->with(['attributeOptions.attribute:id,slug,name,type']);
    }

    /**
     * Only active variants.
     */
    public function activeVariants()
    {
        return $this->hasMany(ProductVariant::class)
            ->where('is_active', true)
            ->with(['attributeOptions.attribute:id,slug,name,type']);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeApproved($query)  { return $query->where('is_approved', true); }
    public function scopePending($query)   { return $query->where('is_approved', false); }
    public function scopeActive($query)    { return $query->where('is_active', true); }
    public function scopeAvailable($query) { return $query->where('is_approved', true)->where('is_active', true); }
    public function scopeFeatured($query)  { return $query->where('featured', true); }
    public function scopeInStock($query)   { return $query->where('stock', '>', 0); }

    /**
     * Filter by a specific attribute value.
     *
     * Routing logic:
     *   - is_variant = true  → values live in variant_attribute_values
     *                          (product → product_variants → variant_attribute_values)
     *   - is_variant = false → values live in product_attribute_values
     *
     * $values is always an array of attribute_option_id integers,
     * e.g. attrs[color][]=104 → $values = [104]
     *
     * When multiple option IDs are passed for the same attribute (OR logic):
     *   → product must have a variant matching ANY of the selected option IDs
     *
     * When multiple attributes are filtered (AND logic):
     *   → index() calls this scope once per attribute, Laravel chains as AND
     */
    public function scopeHasAttribute($query, string $attrSlug, array $values)
    {
        // Resolve attribute ID from slug
        $attribute = DB::table('attributes')
            ->where('slug', $attrSlug)
            ->select('id')
            ->first();

        if (!$attribute) {
            // Unknown attribute slug → return no results
            return $query->whereRaw('1 = 0');
        }

        // Check if this attribute is marked is_variant=true in any subcategory
        $isVariant = DB::table('subcategory_attributes')
            ->where('attribute_id', $attribute->id)
            ->where('is_variant', true)
            ->exists();

        // Cast all values to integers (frontend sends option IDs)
        $optionIds = array_values(array_map('intval', $values));

        if ($isVariant) {
            // Values live in variant_attribute_values via the pivot:
            //   product_variants.id = variant_attribute_values.variant_id
            //   variant_attribute_values.attribute_option_id IN ($optionIds)
            return $query->whereExists(function ($sub) use ($optionIds) {
                $sub->select(DB::raw(1))
                    ->from('product_variants')
                    ->join(
                        'variant_attribute_values',
                        'variant_attribute_values.variant_id',
                        '=',
                        'product_variants.id'
                    )
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.is_active', true)
                    ->whereIn('variant_attribute_values.attribute_option_id', $optionIds);
            });
        }

        // Non-variant: values are stored JSON-encoded in product_attribute_values.value
        // e.g. value = '[3,7]' or value = '3'
        // OR logic across the selected option IDs
        return $query->whereExists(function ($sub) use ($attribute, $optionIds) {
            $sub->select(DB::raw(1))
                ->from('product_attribute_values')
                ->whereColumn('product_attribute_values.product_id', 'products.id')
                ->where('product_attribute_values.attribute_id', $attribute->id)
                ->where(function ($orQ) use ($optionIds) {
                    foreach ($optionIds as $id) {
                        $orQ->orWhere('product_attribute_values.value', 'like', '%"' . $id . '"%')
                            ->orWhere('product_attribute_values.value', (string) $id);
                    }
                });
        });
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    /**
     * True if this product has at least one ProductVariant row.
     */
    public function getHasVariantsAttribute(): bool
    {
        // Use loaded relation when available to avoid extra query
        if ($this->relationLoaded('variants')) {
            return $this->variants->isNotEmpty();
        }
        return $this->variants()->exists();
    }

    /**
     * Total stock across all active variants.
     * Used when has_variants = true.
     */
    public function getVariantStockAttribute(): int
    {
        if ($this->relationLoaded('variants')) {
            return (int) $this->variants->sum('stock');
        }
        return (int) $this->variants()->sum('stock');
    }

    /**
     * FIXED: Returns null instead of a broken placeholder URL when no image exists.
     * The placeholder file doesn't exist on disk, causing 404 errors in the browser.
     * Returning null lets the frontend render its own icon fallback cleanly.
     *
     * Also resolves from the already-loaded `images` collection when available
     * (e.g. in SellerProductController::index) to avoid an extra DB query.
     */
    public function getPrimaryImageUrlAttribute(): ?string
    {
        if ($this->relationLoaded('images')) {
            $primary = $this->images->firstWhere('is_primary', true)
                     ?? $this->images->sortBy('order')->first();
            return $primary ? Storage::url($primary->image_path) : null;
        }

        $primary = $this->primaryImage;
        return $primary
            ? Storage::url($primary->image_path)
            : null;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->is_approved && $this->is_active && $this->stock > 0;
    }
    public function syncActiveStatusFromVariants(): void
{
    $totalVariants  = $this->variants()->count();
    $activeVariants = $this->variants()->where('is_active', true)->count();

    $shouldBeActive = $totalVariants > 0 && $activeVariants > 0;

    if ($this->is_active !== $shouldBeActive) {
        $this->is_active = $shouldBeActive;
        $this->saveQuietly();
    }
}
    /**
     * FIXED: Returns null instead of a broken placeholder URL when no image exists.
     */
    public function getPrimaryImageUrl(): ?string
    {
        $primary = $this->primaryImage;
        return $primary
            ? Storage::url($primary->image_path)
            : null;
    }

    public function incrementViews(): void
    {
        $this->increment('views');
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 3) . ' TND';
    }

    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock > 0 && $this->stock < 10;
    }

    /**
     * Save product-level attribute values.
     * $data = ['color' => '[1,2]', 'size' => '[3]', 'brand' => 'Nike', ...]
     */
    public function syncAttributeValues(array $data): void
    {
        $attrMap = Attribute::whereIn('slug', array_keys($data))->pluck('id', 'slug');

        foreach ($data as $slug => $value) {
            if (!isset($attrMap[$slug]) || $value === null || $value === '') continue;

            ProductAttributeValue::updateOrCreate(
                ['product_id' => $this->id, 'attribute_id' => $attrMap[$slug]],
                ['value' => $value]
            );
        }
    }

    /**
     * Return product-level attribute values keyed by slug for the seller form.
     */
    public function getAttributeValuesForForm(): array
    {
        return $this->attributeValues()
            ->with('attribute')
            ->get()
            ->mapWithKeys(function ($pav) {
                $attr  = $pav->attribute;
                $value = $attr->decodeValue($pav->value);
                return [$attr->slug => $value];
            })
            ->toArray();
    }

    /**
     * Sync variants for this product.
     * $variantsData = array of:
     *   ['id' => optional, 'option_ids' => [3,7], 'stock' => 10, 'price_override' => null, 'sku' => null]
     */
    public function syncVariants(array $variantsData): void
    {
        // Delete variants no longer present in the payload
        $incomingIds = collect($variantsData)->pluck('id')->filter()->values()->toArray();
        if (!empty($incomingIds)) {
            $this->variants()->whereNotIn('id', $incomingIds)->delete();
        } else {
            // No IDs at all means full replacement
            $this->variants()->delete();
        }

        foreach ($variantsData as $vData) {
            $variant = isset($vData['id']) && $vData['id']
                ? ProductVariant::find($vData['id'])
                : new ProductVariant(['product_id' => $this->id]);

            if (!$variant) continue;

            $variant->fill([
                'product_id'     => $this->id,
                'sku'            => $vData['sku']            ?? null,
                'price_override' => isset($vData['price_override']) && $vData['price_override'] !== ''
                    ? (float) $vData['price_override']
                    : null,
                'stock'          => (int) ($vData['stock'] ?? 0),
                'is_active'      => (bool) ($vData['is_active'] ?? true),
            ])->save();

            if (isset($vData['option_ids']) && is_array($vData['option_ids'])) {
                $optionIds = array_filter($vData['option_ids'], fn($id) => $id > 0);
                $variant->attributeOptions()->sync($optionIds);
            }
        }
    }
}