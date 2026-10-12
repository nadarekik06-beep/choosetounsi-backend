<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses picked from Intigo's governorates / delegations.
 *
 * orders           city_name / district_name: the exact Intigo names sent with
 *                  the parcel (snapshot); wilaya / delegation keep our canonical
 *                  values for everything else. Old orders keep NULLs and fall
 *                  back to wilaya / delegation.
 * user_addresses   same columns, so a saved address pre-selects the dropdowns;
 *                  an address without them is asked again at the next checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'user_addresses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('intigo_city_id')->nullable()->after('delegation');
                $table->unsignedInteger('intigo_district_id')->nullable()->after('intigo_city_id');
                $table->string('city_name', 120)->nullable()->after('intigo_district_id');
                $table->string('district_name', 120)->nullable()->after('city_name');
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'user_addresses'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['intigo_city_id', 'intigo_district_id', 'city_name', 'district_name']));
        }
    }
};
