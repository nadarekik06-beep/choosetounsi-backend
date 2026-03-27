<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'name_ar', 'slug', 'type',
        'is_required', 'is_filterable', 'is_visible', 'order',
    ];

    protected $casts = [
        'is_required'   => 'boolean',
        'is_filterable' => 'boolean',
        'is_visible'    => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function options()
    {
        return $this->hasMany(AttributeOption::class)->orderBy('order');
    }

    /**
     * Subcategories this attribute is linked to.
     * Pivot carries: is_required, is_variant, order
     */
    public function subcategories()
    {
        return $this->belongsToMany(Subcategory::class, 'subcategory_attributes')
            ->withPivot('is_required', 'is_variant', 'order');
    }

    public function productValues()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Decode a stored product_attribute_value.value string.
     * select/multiselect/color → array of option IDs
     * text/number/boolean     → raw scalar
     */
    public function decodeValue(?string $raw): mixed
    {
        if ($raw === null) return null;

        if (in_array($this->type, ['select', 'multiselect', 'color'])) {
            $decoded = json_decode($raw, true);
            return $decoded ?? [];
        }

        if ($this->type === 'boolean') return (bool) $raw;
        if ($this->type === 'number')  return is_numeric($raw) ? (float) $raw : null;

        return $raw;
    }
}