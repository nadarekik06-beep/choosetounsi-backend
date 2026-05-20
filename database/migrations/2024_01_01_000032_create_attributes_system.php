<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Attributes (e.g. "Size", "Color", "Material") ──────────────────
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('slug');
            $table->enum('type', [
                'select',
                'multiselect',
                'text',
                'number',
                'boolean',
                'color',
            ])->default('select');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->unique('slug');
        });

        // ── Predefined option values (for select / multiselect / color) ────
        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->onDelete('cascade');
            $table->string('value');
            $table->string('value_ar')->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['attribute_id', 'order']);
        });

        // ── Map: which attributes apply to which subcategory ───────────────
        Schema::create('subcategory_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcategory_id')->constrained()->onDelete('cascade');
            $table->foreignId('attribute_id')->constrained()->onDelete('cascade');
            $table->boolean('is_required')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->unique(['subcategory_id', 'attribute_id']);
        });

        // NOTE: product_attribute_values is created in 2024_01_01_000042_create_product_attribute_values
        // AFTER the products table exists. Do NOT create it here.
    }

    public function down(): void
    {
        Schema::dropIfExists('subcategory_attributes');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attributes');
    }
};
