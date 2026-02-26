<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_ar',
        'slug',
        'description',
        'icon',
        'image',
        'is_active',
        'order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ═══════════════════════════════════════════════
    // BOOT METHOD - Auto-generate slug
    // ═══════════════════════════════════════════════

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    // ═══════════════════════════════════════════════
    // RELATIONSHIPS
    // ═══════════════════════════════════════════════

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function activeProducts()
    {
        return $this->products()
            ->where('is_approved', true)
            ->where('is_active', true);
    }

    // ═══════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }

    // ═══════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════

    public function getProductCount()
    {
        return $this->activeProducts()->count();
    }
}