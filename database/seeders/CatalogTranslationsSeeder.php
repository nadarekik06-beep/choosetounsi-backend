<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fills name_fr / name_ar (categories, subcategories, attributes) and value_fr / value_ar
 * (attribute_options) from database/data/catalog_translations.php, matched on the English
 * name. Only empty fields are filled, so a value an admin already typed is never overwritten
 * and the seeder can run any number of times.
 *
 * Called by CategorySeeder, SubcategoryAndAttributeSeeder and the 2026_09_25_100002 migration.
 *
 * Run: php artisan db:seed --class=CatalogTranslationsSeeder
 */
class CatalogTranslationsSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('data/catalog_translations.php');

        foreach (['categories', 'subcategories', 'attributes'] as $table) {
            foreach ($data[$table] as $name => [$fr, $ar]) {
                DB::table($table)->where('name', $name)->whereNull('name_fr')->update(['name_fr' => $fr]);
                DB::table($table)->where('name', $name)->where(fn ($q) => $q->whereNull('name_ar')->orWhere('name_ar', ''))
                    ->update(['name_ar' => $ar]);
            }
        }

        $attributeIds = DB::table('attributes')->pluck('id', 'name');
        foreach ($data['attribute_options'] as $attribute => $options) {
            $attributeId = $attributeIds[$attribute] ?? null;
            if (!$attributeId) {
                continue;
            }
            foreach ($options as $value => [$fr, $ar]) {
                $q = fn () => DB::table('attribute_options')->where('attribute_id', $attributeId)->where('value', $value);
                $q()->whereNull('value_fr')->update(['value_fr' => $fr]);
                $q()->where(fn ($w) => $w->whereNull('value_ar')->orWhere('value_ar', ''))->update(['value_ar' => $ar]);
            }
        }
    }
}
