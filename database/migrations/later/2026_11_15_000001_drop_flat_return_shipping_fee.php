<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 2 of the per-size after-sale fees: drop the old flat
 * delivery_settings.return_shipping_fee (no code reads it since
 * 2026_10_31_000001_add_after_sale_and_exchange_fees_per_size).
 *
 * Lives in migrations/later/ so a normal `php artisan migrate` skips it.
 * Run it by hand once production is stable:
 *
 *   php artisan migrate --path=database/migrations/later/2026_11_15_000001_drop_flat_return_shipping_fee.php
 *
 * Complaints keep their own frozen fee (complaints.return_shipping_fee):
 * that column is NOT touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('delivery_settings', 'return_shipping_fee')) {
            return;
        }
        // Last value, for the record
        $old = DB::table('delivery_settings')->where('id', 1)->value('return_shipping_fee');
        if (Schema::hasTable('delivery_setting_changes')) {
            DB::table('delivery_setting_changes')->insert([
                'field' => 'return_shipping_fee', 'old_value' => $old, 'new_value' => 'removed (per-size after-sale fees)',
                'changed_by' => null, 'created_at' => now(),
            ]);
        }
        Schema::table('delivery_settings', fn (Blueprint $table) => $table->dropColumn('return_shipping_fee'));
    }

    public function down(): void
    {
        if (Schema::hasColumn('delivery_settings', 'return_shipping_fee')) {
            return;
        }
        Schema::table('delivery_settings', function (Blueprint $table) {
            $table->decimal('return_shipping_fee', 12, 3)->default(0);
        });
        // Back to the S size's after-sale fee
        $s = DB::table('delivery_size_rates')->where('size', 'S')->value('after_sale_return_fee');
        DB::table('delivery_settings')->where('id', 1)->update(['return_shipping_fee' => $s ?? 0]);
    }
};
