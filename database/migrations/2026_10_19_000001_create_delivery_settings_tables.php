<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-controlled delivery pricing (one external delivery company).
 *
 * delivery_settings: a single row (id = 1), edited from the admin
 * "Delivery & Fees" page. Seeded with what the platform charged until now
 * (customer fee 8, agency cost and return fee from the old env values), so
 * nothing changes for customers until the admin edits them.
 *
 * delivery_setting_changes: one row per changed field (who, when, old → new).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('client_delivery_fee', 12, 3);
            $table->decimal('agency_delivery_cost', 12, 3);
            $table->decimal('seller_free_delivery_contribution', 12, 3);
            $table->decimal('return_shipping_fee', 12, 3);
            $table->decimal('refused_parcel_agency_fee', 12, 3);
            $table->string('refused_parcel_fee_paid_by', 10)->default('platform'); // platform | seller
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('field', 64);
            $table->string('old_value', 64)->nullable();
            $table->string('new_value', 64)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('created_at');
        });

        $agency = round((float) env('SHIPPING_AGENCY_COST', 8.0), 3);
        DB::table('delivery_settings')->insert([
            'id'                                => 1,
            'client_delivery_fee'               => 8.000,
            'agency_delivery_cost'              => $agency,
            // Until now a free-shipping seller paid the whole agency cost
            'seller_free_delivery_contribution' => $agency,
            'return_shipping_fee'               => round((float) env('RETURN_SHIPPING_FEE', $agency), 3),
            'refused_parcel_agency_fee'         => $agency,
            'refused_parcel_fee_paid_by'        => 'platform',
            'created_at'                        => now(),
            'updated_at'                        => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_setting_changes');
        Schema::dropIfExists('delivery_settings');
    }
};
