<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'name_ar', 'slug', 'description', 'icon', 'image', 'is_active', 'order',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Active + approved products
     */
    public function activeProducts()
    {
        return $this->products()->where('is_approved', true)->where('is_active', true);
    }

    /**
     * All subcategories (ordered)
     */
    public function subcategories()
    {
        return $this->hasMany(Subcategory::class)->orderBy('order');
    }

    /**
     * Active subcategories
     */
    public function activeSubcategories()
    {
        return $this->subcategories()->where('is_active', true);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($query)   { return $query->where('is_active', true); }
    public function scopeOrdered($query)  { return $query->orderBy('order', 'asc'); }
}