<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductImagesTable extends Migration
{
    public function up()
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('image_path');
            $table->integer('order')->default(0); // First image is primary
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            
            $table->index(['product_id', 'order']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_images');
    }
}