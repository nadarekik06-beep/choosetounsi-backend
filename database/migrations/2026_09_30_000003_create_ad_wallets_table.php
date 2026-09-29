<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One prepaid ad wallet per seller. Clicks spend the free monthly credit first
 * (non-withdrawable, expires at month end), then the paid balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('balance', 12, 3)->default(0)->comment('Paid, withdrawable balance (TND)');
            $table->decimal('credit_balance', 12, 3)->default(0)->comment('Free monthly plan credit (TND)');
            $table->timestamp('credit_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_wallets');
    }
};
