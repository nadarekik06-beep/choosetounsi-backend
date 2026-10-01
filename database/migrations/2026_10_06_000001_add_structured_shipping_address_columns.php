<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured, Tunisia-shaped addresses.
 *
 * orders            — the shipping address is a SNAPSHOT taken at checkout. It
 *                     already had wilaya / address (street) / phone / notes;
 *                     this adds the fields the courier needs. Old orders keep
 *                     NULLs and are shown as "legacy" in the admin.
 * user_addresses    — the buyer's address book gets the same fields so a saved
 *                     address can pre-fill checkout completely.
 * seller_applications — the seller's PICKUP address. wilaya + city already
 *                     existed; street and postal code are new.
 *
 * Everything is nullable: no existing row becomes invalid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'recipient_name')) {
                $table->string('recipient_name', 120)->nullable()->after('wilaya');
            }
            if (!Schema::hasColumn('orders', 'phone_secondary')) {
                $table->string('phone_secondary', 20)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('orders', 'delegation')) {
                $table->string('delegation', 100)->nullable()->after('recipient_name');
            }
            if (!Schema::hasColumn('orders', 'postal_code')) {
                $table->string('postal_code', 4)->nullable()->after('delegation');
            }
        });

        Schema::table('user_addresses', function (Blueprint $table) {
            if (!Schema::hasColumn('user_addresses', 'recipient_name')) {
                $table->string('recipient_name', 120)->nullable()->after('label');
            }
            if (!Schema::hasColumn('user_addresses', 'delegation')) {
                $table->string('delegation', 100)->nullable()->after('wilaya');
            }
            if (!Schema::hasColumn('user_addresses', 'postal_code')) {
                $table->string('postal_code', 4)->nullable()->after('address');
            }
            if (!Schema::hasColumn('user_addresses', 'phone_secondary')) {
                $table->string('phone_secondary', 20)->nullable()->after('phone');
            }
        });

        Schema::table('seller_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_applications', 'pickup_address')) {
                $table->string('pickup_address', 500)->nullable()->after('city')
                      ->comment('Street address where the courier picks up parcels');
            }
            if (!Schema::hasColumn('seller_applications', 'pickup_postal_code')) {
                $table->string('pickup_postal_code', 4)->nullable()->after('pickup_address');
            }
            if (!Schema::hasColumn('seller_applications', 'pickup_notes')) {
                $table->string('pickup_notes', 500)->nullable()->after('pickup_postal_code')
                      ->comment('Landmark / opening hours for the courier');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'phone_secondary', 'delegation', 'postal_code']);
        });
        Schema::table('user_addresses', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'delegation', 'postal_code', 'phone_secondary']);
        });
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->dropColumn(['pickup_address', 'pickup_postal_code', 'pickup_notes']);
        });
    }
};
