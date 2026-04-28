<?php
// database/migrations/2025_xx_xx_000002_create_pack_items_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')
                ->constrained('packs')
                ->onDelete('cascade');
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('cascade');

            // nullable — if product has no variants
            $table->foreignId('variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();

            $table->unsignedInteger('quantity')->default(1);

            // Snapshot of the price at pack-creation time (for savings display)
            $table->decimal('unit_price_snapshot', 10, 3)->default(0)
                ->comment('Price at time of pack creation — used for savings calculation');

            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['pack_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_items');
    }
};