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
     * Attributes linked to this subcategory via the subcategory_attributes pivot.
     * The pivot carries is_required and order overrides per subcategory.
     */
    public function attributes()
    {
        return $this->belongsToMany(
            Attribute::class,
            'subcategory_attributes',
            'subcategory_id',
            'attribute_id'
        )
        ->withPivot('is_required', 'order')
        ->with('options')          // always eager-load options
        ->orderBy('subcategory_attributes.order');
    }
}