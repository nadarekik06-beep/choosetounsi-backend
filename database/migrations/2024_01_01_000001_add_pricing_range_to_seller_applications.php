<?php
// database/migrations/2024_01_01_000001_add_pricing_range_to_seller_applications.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->enum('pricing_range', ['budget', 'mid', 'premium'])
                  ->nullable()
                  ->after('plan')
                  ->comment('Seller price positioning: budget / mid / premium');
        });
    }

    public function down(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->dropColumn('pricing_range');
        });
    }
};