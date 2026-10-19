<?php

use Database\Seeders\CatalogTranslationsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-language catalog:
 *  - name_fr on categories / subcategories / attributes, value_fr on attribute_options
 *    (Arabic columns already exist; the base column stays English)
 *  - products.translations: Groq translations of name / descriptions, cached per locale
 *
 * Existing rows are pre-filled by CatalogTranslationsSeeder from database/data/catalog_translations.php
 * (matched on the English name, never overwriting a value an admin already typed).
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

        // Rows that already exist; on a fresh install the catalog seeders run it again afterwards.
        (new CatalogTranslationsSeeder)->run();
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
