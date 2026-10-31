<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * After-sale fees per parcel size (admin "Delivery & Fees").
 *
 * Intigo bills an after-sale return as a new normal parcel of that size, and
 * an exchange at its own price, so one flat amount lost money on L / XL.
 *
 *   after_sale_return_fee       charged for a complaint return of that size
 *   after_sale_use_agency_cost  on (default): the fee follows agency_cost
 *   exchange_fee                what Intigo bills for an exchange parcel
 *
 * Both start at the size's Intigo delivery cost. The old flat
 * delivery_settings.return_shipping_fee is kept (no longer read): it is
 * dropped later by database/migrations/later/…_drop_flat_return_shipping_fee.php.
 *
 * Also: the refused-parcel fee (return_fee) becomes 4.280 on every size,
 * as Intigo bills it. Every change goes to the settings history.
 */
return new class extends Migration
{
    private const REFUSED_FEE = '4.280';

    public function up(): void
    {
        Schema::table('delivery_size_rates', function (Blueprint $table) {
            $table->decimal('after_sale_return_fee', 12, 3)->default(0)->after('seller_contribution');
            $table->boolean('after_sale_use_agency_cost')->default(true)->after('after_sale_return_fee');
            $table->decimal('exchange_fee', 12, 3)->default(0)->after('after_sale_use_agency_cost');
        });

        $now  = now();
        $flat = DB::table('delivery_settings')->where('id', 1)->value('return_shipping_fee');
        $log  = [];

        foreach (DB::table('delivery_size_rates')->get() as $rate) {
            DB::table('delivery_size_rates')->where('id', $rate->id)->update([
                'after_sale_return_fee'      => $rate->agency_cost,
                'after_sale_use_agency_cost' => true,
                'exchange_fee'               => $rate->agency_cost,
                'return_fee'                 => self::REFUSED_FEE,
                'updated_at'                 => $now,
            ]);
            $log[] = ['field' => "{$rate->size}.after_sale_return_fee", 'old_value' => $flat, 'new_value' => $rate->agency_cost . ' (Intigo delivery cost)'];
            $log[] = ['field' => "{$rate->size}.exchange_fee", 'old_value' => null, 'new_value' => (string) $rate->agency_cost];
            if (number_format((float) $rate->return_fee, 3, '.', '') !== self::REFUSED_FEE) {
                $log[] = ['field' => "{$rate->size}.return_fee", 'old_value' => (string) $rate->return_fee, 'new_value' => self::REFUSED_FEE];
            }
        }

        // The flat refused column mirrors size S
        DB::table('delivery_settings')->where('id', 1)->update(['refused_parcel_agency_fee' => self::REFUSED_FEE, 'updated_at' => $now]);

        if ($log && Schema::hasTable('delivery_setting_changes')) {
            DB::table('delivery_setting_changes')->insert(array_map(fn ($c) => $c + ['changed_by' => null, 'created_at' => $now], $log));
        }

        Cache::forget('delivery_settings.v2');
        Cache::forget('delivery_settings.v3');
    }

    public function down(): void
    {
        Schema::table('delivery_size_rates', function (Blueprint $table) {
            $table->dropColumn(['after_sale_return_fee', 'after_sale_use_agency_cost', 'exchange_fee']);
        });
        Cache::forget('delivery_settings.v2');
        Cache::forget('delivery_settings.v3');
    }
};
