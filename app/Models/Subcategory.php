<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subcategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'name_ar', 'slug', 'icon', 'is_active', 'order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * ALL attributes linked to this subcategory.
     * Pivot carries: is_required, is_variant, order
     */
    public function attributes()
    {
        return $this->belongsToMany(
            Attribute::class,
            'subcategory_attributes',
            'subcategory_id',
            'attribute_id'
        )
        ->withPivot('is_required', 'is_variant', 'order')
        ->with('options')
        ->orderBy('subcategory_attributes.order');
    }

    /**
     * Only the attributes marked as variant axes for this subcategory.
     * These generate the combination matrix (Color×Size, RAM×Storage, etc.)
     */
    public function variantAttributes()
    {
        return $this->belongsToMany(
            Attribute::class,
            'subcategory_attributes',
            'subcategory_id',
            'attribute_id'
        )
        ->wherePivot('is_variant', true)
        ->withPivot('is_required', 'is_variant', 'order')
        ->with('options')
        ->orderBy('subcategory_attributes.order');
    }

    /**
     * Only informational (non-variant) attributes.
     */
    public function infoAttributes()
    {
        return $this->belongsToMany(
            Attribute::class,
            'subcategory_attributes',
            'subcategory_id',
            'attribute_id'
        )
        ->wherePivot('is_variant', false)
        ->withPivot('is_required', 'is_variant', 'order')
        ->with('options')
        ->orderBy('subcategory_attributes.order');
    }
}