<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Drop the old constraint (user_id + product_id only)
            $table->dropUnique(['user_id', 'product_id']);

            // Add the correct constraint that includes variant_id
            $table->unique(
                ['user_id', 'product_id', 'variant_id'],
                'carts_user_product_variant_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_user_product_variant_unique');
            $table->unique(['user_id', 'product_id']);
        });
    }
};