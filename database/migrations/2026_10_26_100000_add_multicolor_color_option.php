<?php

use App\Support\Multicolor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only (no schema change), safe to run again:
 *  - the "Multicolore" option of the color attribute, last in the palette, with a plain
 *    hex in the column (the model serves the gradient). Key: color attribute + value "Multicolor".
 *  - the hidden "multicolor-label" text attribute holding the seller's description
 *    per product (product_attribute_values). Key: its unique slug.
 */
class AddMulticolorColorOption extends Migration
{
    public function up()
    {
        $now = now();

        DB::table('attributes')->insertOrIgnore([
            'name'          => 'Multicolor description',
            'name_ar'       => 'وصف الألوان',
            'name_fr'       => 'Précision des couleurs',
            'slug'          => Multicolor::LABEL_ATTRIBUTE,
            'type'          => 'text',
            'is_required'   => false,
            'is_filterable' => false,
            'is_visible'    => false,
            'order'         => 999,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $colorId = DB::table('attributes')->where('slug', 'color')->value('id');
        if (!$colorId) {
            echo '[multicolor] no color attribute yet: option not added (the seeder adds it)' . PHP_EOL;
            return;
        }

        $exists = DB::table('attribute_options')
            ->where('attribute_id', $colorId)->where('value', Multicolor::VALUE)->exists();
        if ($exists) return;

        DB::table('attribute_options')->insert([
            'attribute_id' => $colorId,
            'value'        => Multicolor::VALUE,
            'value_fr'     => Multicolor::VALUE_FR,
            'value_ar'     => Multicolor::VALUE_AR,
            'color_hex'    => Multicolor::HEX,
            'order'        => (int) DB::table('attribute_options')->where('attribute_id', $colorId)->max('order') + 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function down()
    {
        // Only removes what nothing uses yet: variants and descriptions are never deleted.
        $colorId = DB::table('attributes')->where('slug', 'color')->value('id');
        $optId   = $colorId ? DB::table('attribute_options')
            ->where('attribute_id', $colorId)->where('value', Multicolor::VALUE)->value('id') : null;
        if ($optId && !DB::table('variant_attribute_values')->where('attribute_option_id', $optId)->exists()) {
            DB::table('attribute_options')->where('id', $optId)->delete();
        }

        $attrId = DB::table('attributes')->where('slug', Multicolor::LABEL_ATTRIBUTE)->value('id');
        if ($attrId && !DB::table('product_attribute_values')->where('attribute_id', $attrId)->exists()) {
            DB::table('attributes')->where('id', $attrId)->delete();
        }
    }
}
