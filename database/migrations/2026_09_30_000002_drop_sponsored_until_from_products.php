<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** products.sponsored_until was never set (campaign end dates live on sponsorships). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'sponsored_until')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('sponsored_until'));
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'sponsored_until')) {
            Schema::table('products', fn (Blueprint $table) => $table->timestamp('sponsored_until')->nullable()->after('is_sponsored'));
        }
    }
};
