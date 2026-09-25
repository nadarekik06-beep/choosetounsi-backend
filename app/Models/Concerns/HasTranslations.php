<?php

namespace App\Models\Concerns;

use App\Support\Localization;

/**
 * Serves translated values for the model's $translatable attributes when storefront
 * localization is active (see App\Support\Localization).
 *
 * Column-backed models (categories, subcategories, attributes, options) keep the English
 * source in the base column and translations in "<attr>_fr" / "<attr>_ar" columns.
 * Missing translations fall back to French, then to the base value.
 *
 * The untranslated value stays available as "base_<attr>" in arrays/JSON, for places
 * that store the value (e.g. seller applications storing category names).
 */
trait HasTranslations
{
    public function translatableAttributes(): array
    {
        return property_exists($this, 'translatable') ? $this->translatable : [];
    }

    /** Translation of $key for the current locale, or $value when there is none. */
    protected function translateAttribute(string $key, $value)
    {
        $locale = Localization::locale();
        if ($locale === 'en') {
            return $value;
        }

        $attributes = $this->getAttributes();

        return ($attributes["{$key}_{$locale}"] ?? null)
            ?: (($attributes["{$key}_fr"] ?? null) ?: $value);
    }

    public function getAttributeValue($key)
    {
        $value = parent::getAttributeValue($key);

        if (Localization::active() && in_array($key, $this->translatableAttributes(), true)) {
            return $this->translateAttribute($key, $value);
        }

        return $value;
    }

    public function attributesToArray()
    {
        $array = parent::attributesToArray();

        if (!Localization::active()) {
            return $array;
        }

        $withBase = property_exists($this, 'translatableBase') ? $this->translatableBase : $this->translatableAttributes();

        foreach ($this->translatableAttributes() as $key) {
            if (array_key_exists($key, $array)) {
                if (in_array($key, $withBase, true)) {
                    $array["base_{$key}"] = $array[$key];
                }
                $array[$key]          = $this->translateAttribute($key, $array[$key]);
            }
        }

        return $array;
    }
}
