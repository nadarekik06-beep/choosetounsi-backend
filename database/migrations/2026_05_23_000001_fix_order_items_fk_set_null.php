<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Find and drop existing FK constraints on product_id and variant_id
        $fks = DB::select("
            SELECT rc.CONSTRAINT_NAME
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            JOIN information_schema.KEY_COLUMN_USAGE kcu 
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
            WHERE kcu.TABLE_NAME = 'order_items' 
                AND kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.COLUMN_NAME IN ('product_id', 'variant_id')
        ");

        Schema::table('order_items', function (Blueprint $table) use ($fks) {
            foreach ($fks as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }
        });

        // Step 2: Make both columns nullable
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->unsignedBigInteger('variant_id')->nullable()->change();
        });

        // Step 3: Re-add FKs with SET NULL
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->onDelete('set null');

            $table->foreign('variant_id')
                  ->references('id')->on('product_variants')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        // Drop SET NULL FKs
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['variant_id']);
        });

        // Restore CASCADE FKs (original behavior)
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            $table->unsignedBigInteger('variant_id')->nullable(false)->change();

            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->onDelete('cascade');

            $table->foreign('variant_id')
                  ->references('id')->on('product_variants')
                  ->onDelete('cascade');
        });
    }
};