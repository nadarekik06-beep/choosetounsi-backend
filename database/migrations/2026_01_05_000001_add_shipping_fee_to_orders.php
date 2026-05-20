<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add shipping_fee to orders table.
 *
 * Existing orders default to 8.000 DT (the platform's fixed rate).
 * Future orders will have the fee stored explicitly at checkout time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'shipping_fee')) {
                $table->decimal('shipping_fee', 8, 3)
                      ->default(8.000)
                      ->after('total_amount')
                      ->comment('Shipping fee in TND. Defaults to 8.000 for existing orders.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'shipping_fee')) {
                $table->dropColumn('shipping_fee');
            }
        });
    }
};