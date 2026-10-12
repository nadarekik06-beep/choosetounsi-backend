<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery prices per parcel size, part of the admin "Delivery & Fees"
 * settings (App\Services\Delivery\DeliverySettings: same page, same change log).
 *
 *   client_fee           what the client pays for a parcel of that size
 *   agency_cost          what Intigo charges us for delivering it
 *   return_fee           what Intigo charges when the parcel comes back (refused)
 *   seller_contribution  what a free-shipping seller pays per parcel
 *
 * Seeded from the single amounts in force (S, L and XL identical), so nothing
 * changes for customers until the admin sets the sizes apart. The flat columns
 * of delivery_settings stay as the S mirror (older readers keep working).
 *
 * delivery_settings.intigo_can_open: "the customer may open the parcel before
 * paying", sent with every Intigo parcel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_size_rates', function (Blueprint $table) {
            $table->id();
            $table->enum('size', ['S', 'L', 'XL'])->unique();
            $table->decimal('client_fee', 12, 3);
            $table->decimal('agency_cost', 12, 3);
            $table->decimal('return_fee', 12, 3);
            $table->decimal('seller_contribution', 12, 3);
            $table->timestamps();
        });

        Schema::table('delivery_settings', function (Blueprint $table) {
            $table->boolean('intigo_can_open')->default(false)->after('refused_parcel_fee_paid_by');
        });

        $row = DB::table('delivery_settings')->where('id', 1)->first();
        $now = now();
        foreach (['S', 'L', 'XL'] as $size) {
            DB::table('delivery_size_rates')->insert([
                'size'                => $size,
                'client_fee'          => $row->client_delivery_fee ?? 8,
                'agency_cost'         => $row->agency_delivery_cost ?? 8,
                'return_fee'          => $row->refused_parcel_agency_fee ?? 8,
                'seller_contribution' => $row->seller_free_delivery_contribution ?? 8,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
        DB::table('delivery_settings')->where('id', 1)->update([
            'intigo_can_open' => filter_var(env('INTIGO_CAN_OPEN_DEFAULT', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function down(): void
    {
        Schema::table('delivery_settings', fn (Blueprint $table) => $table->dropColumn('intigo_can_open'));
        Schema::dropIfExists('delivery_size_rates');
    }
};
