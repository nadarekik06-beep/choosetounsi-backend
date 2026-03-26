<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'price'       => 'decimal:2',
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
     * Raw attribute value rows.
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

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeApproved($query)  { return $query->where('is_approved', true); }
    public function scopePending($query)   { return $query->where('is_approved', false); }
    public function scopeActive($query)    { return $query->where('is_active', true); }
    public function scopeAvailable($query) { return $query->where('is_approved', true)->where('is_active', true); }
    public function scopeFeatured($query)  { return $query->where('featured', true); }
    public function scopeInStock($query)   { return $query->where('stock', '>', 0); }

    /**
     * Filter by a specific attribute value.
     * Usage: ->hasAttribute('color', [3, 7])  (option IDs)
     *        ->hasAttribute('brand', 'Nike')   (text)
     */
    public function scopeHasAttribute($query, string $attrSlug, $value)
    {
        return $query->whereHas('attributeValues', function ($q) use ($attrSlug, $value) {
            $q->whereHas('attribute', fn($a) => $a->where('slug', $attrSlug));

            if (is_array($value)) {
                // For multiselect: check that JSON contains ALL given option IDs
                foreach ($value as $v) {
                    $q->where('value', 'like', '%"' . $v . '"%');
                }
            } else {
                $q->where('value', $value);
            }
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->is_approved && $this->is_active && $this->stock > 0;
    }

    public function getPrimaryImageUrl(): string
    {
        $primary = $this->primaryImage;
        return $primary
            ? Storage::url($primary->image_path)
            : asset('images/placeholder-product.jpg');
    }

    public function getPrimaryImageUrlAttribute(): string
    {
        return $this->getPrimaryImageUrl();
    }

    public function incrementViews(): void
    {
        $this->increment('views');
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2) . ' TND';
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
     * Save attribute values for this product.
     * $data = ['color' => '[1,2]', 'size' => '[3]', 'brand' => 'Nike', ...]
     * Keys are attribute slugs, values are already-serialized strings.
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
     * Return attribute values as a keyed array suitable for the front-end form.
     * [ 'color' => [1,2], 'brand' => 'Nike', ... ]
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
}