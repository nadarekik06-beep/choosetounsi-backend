<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-seller delivery fee mode (App\Services\Delivery\DeliveryMode).
 *
 * users.delivery_fee_mode            'included' (default: the fee is inside the displayed prices, as before)
 *                                    or 'separate' (base prices shown, the fee charged apart at checkout)
 * seller_delivery_mode_changes       who switched a seller, from what to what, when
 * seller_orders (frozen at checkout)
 *   delivery_mode          mode of the parcel's seller when the order was placed
 *   delivery_fee_charged   delivery charged to the buyer as its own line (0 when included)
 *   delivery_cost          Intigo tariff of the parcel
 *
 * Additive only. No existing row changes: older orders read as 'included' with
 * delivery_fee_charged 0 and delivery_cost NULL (reports fall back to shipping_cost);
 * their delivery_fee, shipping_cost and every other amount stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'delivery_fee_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('delivery_fee_mode', ['included', 'separate'])->default('included');
            });
        }

        if (!Schema::hasTable('seller_delivery_mode_changes')) {
            Schema::create('seller_delivery_mode_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index();
                $table->string('old_mode', 10);
                $table->string('new_mode', 10);
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasColumn('seller_orders', 'delivery_mode')) {
            Schema::table('seller_orders', function (Blueprint $table) {
                $table->enum('delivery_mode', ['included', 'separate'])->default('included');
                $table->decimal('delivery_fee_charged', 12, 3)->default(0);
                $table->decimal('delivery_cost', 12, 3)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('seller_orders', 'delivery_mode')) {
            Schema::table('seller_orders', fn (Blueprint $table) => $table->dropColumn(['delivery_mode', 'delivery_fee_charged', 'delivery_cost']));
        }
        Schema::dropIfExists('seller_delivery_mode_changes');
        if (Schema::hasColumn('users', 'delivery_fee_mode')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('delivery_fee_mode'));
        }
    }
};
