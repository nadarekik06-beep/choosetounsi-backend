<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasTranslations;

class AttributeOption extends Model
{
    use HasFactory, HasTranslations;

    protected $translatable = ['value'];

    protected $fillable = ['attribute_id', 'value', 'value_ar', 'value_fr', 'color_hex', 'order'];

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