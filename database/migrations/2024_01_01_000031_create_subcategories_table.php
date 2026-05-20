<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('slug');
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'slug']);
            $table->index(['category_id', 'is_active']);
        });

        // NOTE: subcategory_id is added to products in update_products_table_for_production
        // which runs AFTER the products table is created (2024_01_01_000040).
    }

    public function down(): void
    {
        Schema::dropIfExists('subcategories');
    }
};
