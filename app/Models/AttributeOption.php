<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasTranslations;
use App\Support\Multicolor;

class AttributeOption extends Model
{
    use HasFactory, HasTranslations {
        getAttributeValue as translatedAttributeValue;
        attributesToArray as translatedAttributesToArray;
    }

    protected $translatable = ['value'];

    protected $fillable = ['attribute_id', 'value', 'value_ar', 'value_fr', 'color_hex', 'order'];

    protected $appends = ['is_multicolor'];

    /**
     * In-memory display value (never saved): the seller's Multicolore description
     * ("Noir / Blanc"), set by Multicolor::applyToProduct().
     */
    public ?string $displayLabel = null;

    // ── Multicolore ────────────────────────────────────────────────────────

    public function getIsMulticolorAttribute(): bool
    {
        return Multicolor::isOption($this);
    }

    /** Multicolore serves its gradient (CSS background); the column keeps a plain hex. */
    public function getColorHexAttribute($value)
    {
        return $value !== null && Multicolor::isOption($this) ? Multicolor::GRADIENT : $value;
    }

    /** The translated option name, ignoring displayLabel. */
    public function translatedValue(): ?string
    {
        return $this->translatedAttributeValue('value');
    }

    public function getAttributeValue($key)
    {
        if ($key === 'value' && $this->displayLabel !== null) return $this->displayLabel;
        return $this->translatedAttributeValue($key);
    }

    public function attributesToArray()
    {
        $array = $this->translatedAttributesToArray();
        if ($this->displayLabel !== null && array_key_exists('value', $array)) $array['value'] = $this->displayLabel;
        return $array;
    }

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
