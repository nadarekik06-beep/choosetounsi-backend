<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates users table with role-based access and seller approval system
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            
            // Role management: admin, seller, client
            $table->enum('role', ['admin', 'seller', 'client'])->default('client');
            
            // User status - can be activated/deactivated by admin
            $table->boolean('is_active')->default(true);
            
            // Seller approval system - sellers must be approved before selling
            $table->boolean('is_approved')->default(false)
                ->comment('For sellers: must be approved by admin before they can list products');
            
            $table->rememberToken();
            $table->timestamps();
            
            // Indexes for performance
            $table->index('role');
            $table->index('is_active');
            $table->index(['role', 'is_approved']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};