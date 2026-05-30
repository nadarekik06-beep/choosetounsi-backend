<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds delivery_fee to the products table.
 *
 * Architecture decision: nullable decimal, NOT a boolean flag.
 *   NULL        → platform default fee (currently 8.000 DT)
 *   0.000       → free delivery (seller opted in)
 *   X.XXX > 0  → custom fee (future: per-product/per-seller pricing)
 *
 * This single column handles the current feature AND all future
 * delivery fee extensibility without a second migration.
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('delivery_fee', 8, 3)
                ->nullable()
                ->default(null)
                ->after('price')
                ->comment(
                    'null = platform default (8 DT). ' .
                    '0.000 = free delivery. ' .
                    'X.XXX = custom fee (future). ' .
                    'NEVER calculated on the frontend — always use backend getEffectiveDeliveryFee().'
                );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('delivery_fee');
        });
    }
};