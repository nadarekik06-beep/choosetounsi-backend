<?php
// database/migrations/2026_05_09_000001_update_seller_applications_v2.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            // Subcategory names, index-matched to business_categories
            if (!Schema::hasColumn('seller_applications', 'business_subcategories')) {
                $table->json('business_subcategories')
                      ->nullable()
                      ->after('business_categories')
                      ->comment('Selected subcategory names per selected category');
            }

            // Make description optional (move to step 3, optional)
            $table->text('business_description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->dropColumn('business_subcategories');
            $table->text('business_description')->nullable(false)->change();
        });
    }
};