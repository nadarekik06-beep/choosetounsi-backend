<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller edits on products are applied directly; every save is logged here
 * instead of going through product_update_requests.
 *
 *   product_change_sets  — one row per save (grouped admin notification, revert-all)
 *   product_change_items — one row per changed field (old → new, per-item revert)
 */
class CreateProductChangeLogTables extends Migration
{
    public function up()
    {
        Schema::create('product_change_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 30)->default('seller_edit');   // seller_edit | restock | images | admin_revert
            $table->string('summary', 500)->nullable();
            $table->json('groups')->nullable();                        // e.g. ["price","stock","images"]
            $table->boolean('is_sensitive')->default(false);
            $table->json('sensitive_reasons')->nullable();
            $table->boolean('stock_only')->default(false);
            $table->boolean('notified')->default(false);
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['is_sensitive', 'created_at']);
        });

        Schema::create('product_change_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_set_id')->constrained('product_change_sets')->cascadeOnDelete();
            $table->string('field', 80);          // name | price | variant.12.price_override | images.gallery …
            $table->string('group', 20);          // text | price | stock | category | images | variants | attributes | settings
            $table->string('label', 150);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('revertible')->default(true);
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['change_set_id']);
            $table->index(['group']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_change_items');
        Schema::dropIfExists('product_change_sets');
    }
}
