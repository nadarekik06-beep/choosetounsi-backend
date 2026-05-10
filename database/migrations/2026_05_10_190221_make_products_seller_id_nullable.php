<?php
// database/migrations/xxxx_make_products_seller_id_nullable.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->unsignedBigInteger('seller_id')->nullable()->change();
            $table->foreign('seller_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->unsignedBigInteger('seller_id')->nullable(false)->change();
            $table->foreign('seller_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }
};