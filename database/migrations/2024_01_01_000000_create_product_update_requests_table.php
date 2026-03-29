<?php
// database/migrations/2024_01_01_000000_create_product_update_requests_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductUpdateRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('product_update_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->json('proposed_data');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_comment')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['seller_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_update_requests');
    }
}