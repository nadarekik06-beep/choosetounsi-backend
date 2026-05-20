<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cart
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
        });

        // Favorites
        Schema::table('favorites', function (Blueprint $table) {
            $table->foreignId('variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
            // Drop old unique constraint before adding new one
            $table->dropUnique(['user_id', 'product_id']);
            $table->unique(['user_id', 'product_id', 'variant_id']);
        });

        // Order items
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
            // Store the variant combination label for historical records
            $table->string('variant_label')->nullable()->after('variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn(['variant_id', 'variant_label']);
        });

        Schema::table('favorites', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id', 'variant_id']);
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
            $table->unique(['user_id', 'product_id']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
        });
    }
};