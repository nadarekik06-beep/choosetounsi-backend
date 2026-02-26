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

    protected $appends = ['primary_image_url'];

    // ═══════════════════════════════════════════════════════════════════
    // BOOT METHOD - Auto-generate slug and handle deletions
    // ═══════════════════════════════════════════════════════════════════

    protected static function boot()
    {
        parent::boot();

        // Auto-generate slug on creation
        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        // Delete all images when product is deleted
        static::deleting(function ($product) {
            // Delete image files from storage
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image->image_path);
            }
            // Delete image records
            $product->images()->delete();
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Product belongs to a seller (User)
     */
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }public function orderItems()
{
    return $this->hasMany(OrderItem::class);
}

    /**
     * Product belongs to a category
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Product has many images
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('order');
    }

    /**
     * Get the primary image
     */
    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    // ═══════════════════════════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Scope: Only approved products
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope: Only pending products
     */
    public function scopePending($query)
    {
        return $query->where('is_approved', false);
    }

    /**
     * Scope: Only active products
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Available products (approved AND active)
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_approved', true)
                     ->where('is_active', true);
    }

    /**
     * Scope: Featured products
     */
    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    /**
     * Scope: In stock products
     */
    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }
    
    // ═══════════════════════════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Check if product is available for purchase
     */
    public function isAvailable()
    {
        return $this->is_approved && $this->is_active && $this->stock > 0;
    }

    /**
     * Get primary image URL
     */
    public function getPrimaryImageUrl()
    {
        $primary = $this->primaryImage;
        
        if ($primary) {
            return Storage::url($primary->image_path);
        }
        
        // Return placeholder if no image
        return asset('images/placeholder-product.jpg');
    }

    /**
     * Accessor for primary_image_url (for JSON responses)
     */
    public function getPrimaryImageUrlAttribute()
    {
        return $this->getPrimaryImageUrl();
    }

    /**
     * Increment product views
     */
    public function incrementViews()
    {
        $this->increment('views');
    }

    /**
     * Get formatted price with currency
     */
    public function getFormattedPriceAttribute()
    {
        return number_format($this->price, 2) . ' TND';
    }

    /**
     * Check if product is out of stock
     */
    public function isOutOfStock()
    {
        return $this->stock <= 0;
    }

    /**
     * Check if product is low on stock (less than 10)
     */
    public function isLowStock()
    {
        return $this->stock > 0 && $this->stock < 10;
    }
    
}