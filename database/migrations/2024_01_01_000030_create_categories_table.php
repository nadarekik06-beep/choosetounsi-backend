<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoriesTable extends Migration
{
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., Handicrafts, Food Products, Textiles
            $table->string('name_ar'); // Arabic name
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable(); // Icon name or emoji
            $table->string('image')->nullable(); // Category image
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0); // For ordering display
            $table->timestamps();
        });

        // NOTE: category_id is added to products in update_products_table_for_production
        // which runs AFTER the products table is created (2024_01_01_000040).
    }

    public function down()
    {
        Schema::dropIfExists('categories');
    }
}
