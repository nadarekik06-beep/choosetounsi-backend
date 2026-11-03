<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * delivery_settings.intigo_is_exchange: the admin "Exchange parcel (Échange)"
 * switch on Delivery & Fees, sent as is_exchange with every NEW Intigo parcel
 * (POST /parcels/by-name, next to can_open). Off by default: existing
 * parcels and current behaviour are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('delivery_settings', 'intigo_is_exchange')) {
            return;
        }
        Schema::table('delivery_settings', function (Blueprint $table) {
            $table->boolean('intigo_is_exchange')->default(false)->after('intigo_can_open');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('delivery_settings', 'intigo_is_exchange')) {
            Schema::table('delivery_settings', fn (Blueprint $table) => $table->dropColumn('intigo_is_exchange'));
        }
    }
};
