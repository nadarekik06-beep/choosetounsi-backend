<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributeOption extends Model
{
    use HasFactory;

    protected $fillable = ['attribute_id', 'value', 'value_ar', 'color_hex', 'order'];

    // ── Relationships ──────────────────────────────────────────────────────

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * Variants that include this option.
     */
    public function variants()
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'variant_attribute_values',
            'attribute_option_id',
            'variant_id'
        );
    }
}