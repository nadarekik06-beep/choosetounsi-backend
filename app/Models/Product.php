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
        'is_approved', 'is_active', 'is_platform_product', 'featured', 'views',
        'is_pack', 'season','rejection_reason',
    ];

    protected $casts = [
        'is_approved'         => 'boolean',
        'is_active'           => 'boolean',
        'featured'            => 'boolean',
        'is_platform_product' => 'boolean',
        'price'               => 'decimal:3',
        'is_pack'             => 'boolean',
        'season'              => 'array',   // ← JSON array: ["summer","winter"] stored as JSON in DB
    ];

    // Canonical season values — reuse everywhere (validation, AI engine, frontend)
    public const SEASONS = [
        'all_seasons'    => 'All Seasons',
        'summer'         => 'Summer',
        'winter'         => 'Winter',
        'spring'         => 'Spring',
        'autumn'         => 'Autumn',
        'ramadan'        => 'Ramadan',
        'eid_al_fitr'    => 'Eid al-Fitr',
        'eid_al_adha'    => 'Eid al-Adha',
        'back_to_school' => 'Back to School',
        'new_year'       => 'New Year',
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
            // Ensure season always defaults to an array, never null
            if (empty($product->season)) {
                $product->season = ['all_seasons'];
            }
        });

        static::deleting(function ($product) {
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image->image_path);
            }
            $product->images()->delete();
            $product->attributeValues()->delete();
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

    public function attributeValues()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function attributes()
    {
        return $this->attributeValues()->with(['attribute.options']);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)
            ->with(['attributeOptions.attribute:id,slug,name,type']);
    }

    public function activeVariants()
    {
        return $this->hasMany(ProductVariant::class)
            ->where('is_active', true)
            ->with(['attributeOptions.attribute:id,slug,name,type']);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeApproved($query)      { return $query->where('is_approved', true); }
    public function scopePending($query)        { return $query->where('is_approved', false); }
    public function scopeActive($query)         { return $query->where('is_active', true); }
    public function scopeAvailable($query)      { return $query->where('is_approved', true)->where('is_active', true); }
    public function scopeFeatured($query)       { return $query->where('featured', true); }
    public function scopeInStock($query)        { return $query->where('stock', '>', 0); }
    public function scopePlatform($query)       { return $query->where('is_platform_product', true); }
    public function scopeSeller($query)         { return $query->where('is_platform_product', false); }
    public function scopeAvailableBrand($query) { return $query->where('is_platform_product', true)->where('is_active', true); }

    /**
     * Filter by season — checks if the JSON array contains the given season value.
     * Usage: Product::hasSeason('summer')->get()
     */
    public function scopeHasSeason($query, string $season)
    {
        return $query->where(function ($q) use ($season) {
            $q->whereJsonContains('season', $season)
              ->orWhereJsonContains('season', 'all_seasons');
        });
    }

    /**
     * Filter by a specific attribute value.
     */
    public function scopeHasAttribute($query, string $attrSlug, array $values)
    {
        $attribute = DB::table('attributes')
            ->where('slug', $attrSlug)
            ->select('id')
            ->first();

        if (!$attribute) {
            return $query->whereRaw('1 = 0');
        }

        $isVariant = DB::table('subcategory_attributes')
            ->where('attribute_id', $attribute->id)
            ->where('is_variant', true)
            ->exists();

        $optionIds = array_values(array_map('intval', $values));

        if ($isVariant) {
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

    public function getHasVariantsAttribute(): bool
    {
        if ($this->relationLoaded('variants')) {
            return $this->variants->isNotEmpty();
        }
        return $this->variants()->exists();
    }

    public function getVariantStockAttribute(): int
    {
        if ($this->relationLoaded('variants')) {
            return (int) $this->variants->sum('stock');
        }
        return (int) $this->variants()->sum('stock');
    }

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
        $totalVariants = $this->variants()->count();

        if ($totalVariants === 0) {
            return;
        }

        $activeVariants = $this->variants()->where('is_active', true)->count();
        $shouldBeActive = $activeVariants > 0;

        if ($this->is_active !== $shouldBeActive) {
            $this->is_active = $shouldBeActive;
            $this->saveQuietly();
        }
    }

    public function getPrimaryImageUrl(): ?string
    {
        $primary = $this->primaryImage;
        return $primary
            ? Storage::url($primary->image_path)
            : null;
    }

    public function sponsorships()
    {
        return $this->hasMany(\App\Models\Sponsorship::class);
    }

    public function activeSponsorship()
    {
        return $this->hasOne(\App\Models\Sponsorship::class)
                    ->where('status', 'active')
                    ->orderByDesc('boost_score');
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

    public function syncVariants(array $variantsData): void
    {
        $incomingIds = collect($variantsData)->pluck('id')->filter()->values()->toArray();
        if (!empty($incomingIds)) {
            $this->variants()->whereNotIn('id', $incomingIds)->delete();
        } else {
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