<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add pack support to the existing carts table.
 *
 * When pack_id is set:
 *   - product_id is NULL  (pack row, not a product row)
 *   - pack_price_snapshot stores the seller-defined bundle price
 *   - pack_name stores the pack name at time of adding (for display)
 *   - pack_selections stores JSON: [{pack_item_id, variant_id}]
 *
 * Existing rows (product_id set, pack_id NULL) are completely unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Make product_id nullable so pack rows don't need it
            $table->unsignedBigInteger('product_id')->nullable()->change();

            // Pack reference
            $table->unsignedBigInteger('pack_id')
                ->nullable()
                ->after('product_id')
                ->comment('Set when this cart row represents a pack bundle');

            $table->foreign('pack_id')
                ->references('id')
                ->on('packs')
                ->nullOnDelete();

            // Snapshot of the pack price at time of adding to cart
            $table->decimal('pack_price_snapshot', 10, 3)
                ->nullable()
                ->after('pack_id');

            // Pack name snapshot (for display even if pack is later edited)
            $table->string('pack_name')
                ->nullable()
                ->after('pack_price_snapshot');

            // JSON array of {pack_item_id, variant_id} selections
            $table->json('pack_selections')
                ->nullable()
                ->after('pack_name');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['pack_id']);
            $table->dropColumn(['pack_id', 'pack_price_snapshot', 'pack_name', 'pack_selections']);
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }
};