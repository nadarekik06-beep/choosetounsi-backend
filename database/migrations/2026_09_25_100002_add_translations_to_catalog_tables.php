<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-language catalog:
 *  - name_fr on categories / subcategories / attributes, value_fr on attribute_options
 *    (Arabic columns already exist; the base column stays English)
 *  - products.translations: Groq translations of name / descriptions, cached per locale
 *
 * Existing rows are pre-filled from database/data/catalog_translations.php (matched on the
 * English name, never overwriting a value an admin already typed).
 */
class AddTranslationsToCatalogTables extends Migration
{
    public function up()
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('name_fr')->nullable()->after('name_ar');
        });
        Schema::table('subcategories', function (Blueprint $table) {
            $table->string('name_fr')->nullable()->after('name_ar');
        });
        Schema::table('attributes', function (Blueprint $table) {
            $table->string('name_fr')->nullable()->after('name_ar');
        });
        Schema::table('attribute_options', function (Blueprint $table) {
            $table->string('value_fr')->nullable()->after('value_ar');
        });
        Schema::table('products', function (Blueprint $table) {
            // {"fr": {"name": .., "short_description": .., "description": ..}, "ar": {..}, "en": {..}}
            $table->json('translations')->nullable()->after('short_description');
            $table->char('translations_hash', 32)->nullable()->after('translations');
            $table->timestamp('translated_at')->nullable()->after('translations_hash');
        });

        $this->prefill();
    }

    private function prefill(): void
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

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['translations', 'translations_hash', 'translated_at']);
        });
        Schema::table('attribute_options', function (Blueprint $table) {
            $table->dropColumn('value_fr');
        });
        foreach (['attributes', 'subcategories', 'categories'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('name_fr');
            });
        }
    }
}
