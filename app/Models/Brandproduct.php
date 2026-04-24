<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * App\Models\BrandProduct
 *
 * Platform-owned products for the CHOOSE'Tounsi brand.
 * Completely separate from seller Product model — no seller_id,
 * no approval workflow, no variants, no sponsorships.
 */
class BrandProduct extends Model
{
    use HasFactory;

    protected $table = 'brand_products';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'price',
        'stock',
        'sku',
        'category_id',
        'is_active',
        'featured',
        'views',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'featured'  => 'boolean',
        'price'     => 'decimal:3',
        'stock'     => 'integer',
        'views'     => 'integer',
    ];

    protected $appends = ['primary_image_url'];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = static::uniqueSlug($product->name);
            }
        });

        static::deleting(function ($product) {
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image->image_path);
            }
            $product->images()->delete();
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function images()
    {
        return $this->hasMany(BrandProductImage::class)->orderBy('order');
    }

    public function primaryImage()
    {
        return $this->hasOne(BrandProductImage::class)
            ->where('is_primary', true);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getPrimaryImageUrlAttribute(): ?string
    {
        if ($this->relationLoaded('images')) {
            $primary = $this->images->firstWhere('is_primary', true)
                     ?? $this->images->sortBy('order')->first();
            return $primary ? Storage::url($primary->image_path) : null;
        }

        $primary = $this->primaryImage;
        return $primary ? Storage::url($primary->image_path) : null;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function incrementViews(): void
    {
        $this->increment('views');
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->stock > 0;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock > 0 && $this->stock < 10;
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 3) . ' TND';
    }

    /**
     * Generate a unique slug for brand products.
     * Appends -2, -3, etc. if slug already exists.
     */
    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        return $slug;
    }
}