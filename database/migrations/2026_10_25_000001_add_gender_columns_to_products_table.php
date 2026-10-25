<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalized gender for browsing (Mode & Vêtements → Femme / Homme).
 *
 *   gender          women | men | unisex | kids | null — what the storefront filters on
 *   gender_source   seller | inherited | admin | null  — where `gender` came from
 *   gender_override admin's manual fix; wins over everything else
 *
 * Additive and nullable: existing rows stay as they are until
 * `php artisan products:backfill-gender` fills them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'gender')) {
                $table->string('gender', 10)->nullable();
            }
            if (!Schema::hasColumn('products', 'gender_source')) {
                $table->string('gender_source', 10)->nullable();
            }
            if (!Schema::hasColumn('products', 'gender_override')) {
                $table->string('gender_override', 10)->nullable();
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['category_id', 'gender'], 'products_category_gender_index');
            $table->index(['subcategory_id', 'gender'], 'products_subcategory_gender_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_category_gender_index');
            $table->dropIndex('products_subcategory_gender_index');
            $table->dropColumn(['gender', 'gender_source', 'gender_override']);
        });
    }
};
