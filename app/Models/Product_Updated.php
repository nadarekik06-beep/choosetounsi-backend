<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'category_id',
        'name',
        'slug',
        'description',
        'short_description',
        'price',
        'stock',
        'sku',
        'is_approved',
        'is_active',
        'featured',
        'views',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'is_active' => 'boolean',
        'featured' => 'boolean',
        'price' => 'decimal:2',
    ];

    // ═══════════════════════════════════════════════
    // BOOT - Auto-generate slug
    // ═══════════════════════════════════════════════

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::deleting(function ($product) {
            // Delete all images when product is deleted
            $product->images()->delete();
        });
    }

    // ═══════════════════════════════════════════════
    // RELATIONSHIPS
    // ═══════════════════════════════════════════════

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('order');
    }

    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    // ═══════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_approved', false);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_approved', true)
                     ->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    // ═══════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════

    public function isAvailable()
    {
        return $this->is_approved && $this->is_active && $this->stock > 0;
    }

    public function getPrimaryImageUrl()
    {
        $primary = $this->primaryImage;
        return $primary ? $primary->url : asset('images/placeholder-product.jpg');
    }

    public function incrementViews()
    {
        $this->increment('views');
    }

    public function getFormattedPrice()
    {
        return number_format($this->price, 2) . ' TND';
    }
}