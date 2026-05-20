<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            // Use after('status') — 'plan' column does not exist yet at this point.
            // It is added later in 2026_04_13_174647_add_plan_columns_to_seller_applications_table
            $table->enum('pricing_range', ['budget', 'mid', 'premium'])
                  ->nullable()
                  ->after('status')
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
