<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Changed my mind" returns not allowed (underwear, swimwear, cosmetics, food…).
 * Schema only: nothing is flagged here, `php artisan returns:flag-categories` does it
 * (dry run first). Subcategory NULL = inherits the category; 0 = explicit exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('no_changed_mind_return')->default(false);
        });
        Schema::table('subcategories', function (Blueprint $table) {
            $table->boolean('no_changed_mind_return')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subcategories', fn (Blueprint $t) => $t->dropColumn('no_changed_mind_return'));
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('no_changed_mind_return'));
    }
};
