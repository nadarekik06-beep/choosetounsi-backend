<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates products table with admin approval/moderation system
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            
            // Product belongs to a seller
            $table->foreignId('seller_id')->constrained('users')->onDelete('cascade');
            
            // Basic product information
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock')->default(0);
            $table->string('sku')->unique()->nullable();
            
            // Product moderation - admin must approve products
            $table->boolean('is_approved')->default(false)
                ->comment('Admin must approve product before it appears on storefront');
            
            // Product status - admin can disable products
            $table->boolean('is_active')->default(true)
                ->comment('Admin can deactivate products for policy violations');
            
            // WooCommerce integration ID (optional)
            $table->bigInteger('woocommerce_product_id')->nullable()
                ->comment('Link to WooCommerce product ID for sync');
            
            $table->timestamps();
            $table->softDeletes(); // Soft delete for data retention
            
            // Indexes for performance
            $table->index('seller_id');
            $table->index('is_approved');
            $table->index('is_active');
            $table->index(['is_approved', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};