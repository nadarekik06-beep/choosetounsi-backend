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

        // Add category_id to products table
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('seller_id')->constrained()->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
        
        Schema::dropIfExists('categories');
    }
}