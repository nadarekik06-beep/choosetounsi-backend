<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SubcategoryAndAttributeSeeder
 *
 * Creates common variant attributes (Color, Size, Shoe Size, Material)
 * and links them to ALL existing subcategories so the VariantBuilder
 * shows axes immediately.
 *
 * Run: php artisan db:seed --class=SubcategoryAndAttributeSeeder
 *
 * Safe to run multiple times (uses firstOrCreate).
 */
class SubcategoryAndAttributeSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Ensure core attributes exist ──────────────────────────────────

        $color = Attribute::firstOrCreate(
            ['slug' => 'color'],
            [
                'name'          => 'Color',
                'name_ar'       => 'اللون',
                'type'          => 'color',
                'is_required'   => false,
                'is_filterable' => true,
                'is_visible'    => true,
                'order'         => 1,
            ]
        );

        $size = Attribute::firstOrCreate(
            ['slug' => 'size'],
            [
                'name'          => 'Size',
                'name_ar'       => 'المقاس',
                'type'          => 'select',
                'is_required'   => false,
                'is_filterable' => true,
                'is_visible'    => true,
                'order'         => 2,
            ]
        );

        $shoeSize = Attribute::firstOrCreate(
            ['slug' => 'shoe-size'],
            [
                'name'          => 'Shoe Size',
                'name_ar'       => 'مقاس الحذاء',
                'type'          => 'select',
                'is_required'   => false,
                'is_filterable' => true,
                'is_visible'    => true,
                'order'         => 3,
            ]
        );

        $material = Attribute::firstOrCreate(
            ['slug' => 'material'],
            [
                'name'          => 'Material',
                'name_ar'       => 'المادة',
                'type'          => 'select',
                'is_required'   => false,
                'is_filterable' => true,
                'is_visible'    => true,
                'order'         => 4,
            ]
        );

        // ── 2. Add options ────────────────────────────────────────────────────

        // Color options
        $colorOptions = [
            ['value' => 'Black',  'color_hex' => '#000000', 'order' => 1],
            ['value' => 'White',  'color_hex' => '#FFFFFF', 'order' => 2],
            ['value' => 'Red',    'color_hex' => '#DC2626', 'order' => 3],
            ['value' => 'Blue',   'color_hex' => '#2563EB', 'order' => 4],
            ['value' => 'Green',  'color_hex' => '#16A34A', 'order' => 5],
            ['value' => 'Yellow', 'color_hex' => '#EAB308', 'order' => 6],
            ['value' => 'Pink',   'color_hex' => '#EC4899', 'order' => 7],
            ['value' => 'Navy',   'color_hex' => '#1E3A5F', 'order' => 8],
            ['value' => 'Beige',  'color_hex' => '#D4C5A9', 'order' => 9],
            ['value' => 'Brown',  'color_hex' => '#92400E', 'order' => 10],
            ['value' => 'Grey',   'color_hex' => '#9CA3AF', 'order' => 11],
            ['value' => 'Orange', 'color_hex' => '#F97316', 'order' => 12],
            ['value' => 'Purple', 'color_hex' => '#7C3AED', 'order' => 13],
        ];

        foreach ($colorOptions as $opt) {
            AttributeOption::firstOrCreate(
                ['attribute_id' => $color->id, 'value' => $opt['value']],
                ['color_hex' => $opt['color_hex'], 'order' => $opt['order']]
            );
        }

        // Clothing size options
        $sizeOptions = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
        foreach ($sizeOptions as $i => $val) {
            AttributeOption::firstOrCreate(
                ['attribute_id' => $size->id, 'value' => $val],
                ['order' => $i + 1]
            );
        }

        // Shoe size options (EU)
        $shoeSizes = ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46'];
        foreach ($shoeSizes as $i => $val) {
            AttributeOption::firstOrCreate(
                ['attribute_id' => $shoeSize->id, 'value' => $val],
                ['order' => $i + 1]
            );
        }

        // Material options
        $materials = ['Cotton', 'Polyester', 'Denim', 'Leather', 'Wool', 'Silk', 'Linen', 'Synthetic'];
        foreach ($materials as $i => $val) {
            AttributeOption::firstOrCreate(
                ['attribute_id' => $material->id, 'value' => $val],
                ['order' => $i + 1]
            );
        }

        // ── 3. Link attributes to ALL subcategories ───────────────────────────

        $subcategories = Subcategory::all();

        foreach ($subcategories as $sub) {
            // Color on every subcategory (order 1)
            DB::table('subcategory_attributes')->upsert(
                [
                    'subcategory_id' => $sub->id,
                    'attribute_id'   => $color->id,
                    'is_required'    => 0,
                    'order'          => 1,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ],
                ['subcategory_id', 'attribute_id'],
                ['order', 'updated_at']
            );

            // Size on every subcategory (order 2) — sellers can ignore if not applicable
            DB::table('subcategory_attributes')->upsert(
                [
                    'subcategory_id' => $sub->id,
                    'attribute_id'   => $size->id,
                    'is_required'    => 0,
                    'order'          => 2,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ],
                ['subcategory_id', 'attribute_id'],
                ['order', 'updated_at']
            );
        }

        $this->command->info('✅ Attributes seeded and linked to ' . $subcategories->count() . ' subcategories.');
        $this->command->info('   Color options: ' . count($colorOptions));
        $this->command->info('   Size options:  ' . count($sizeOptions));
        $this->command->info('   Now run: php artisan cache:clear');
    }
}