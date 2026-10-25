<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A "gendered" top category gets the Femme | Homme switch in the mega-menu
 * and the Genre filter on its page. Mode & Vêtements is gendered by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'is_gendered')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->boolean('is_gendered')->default(false);
            });
        }

        DB::table('categories')->where('slug', 'fashion-clothing')->update(['is_gendered' => true]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_gendered');
        });
    }
};
