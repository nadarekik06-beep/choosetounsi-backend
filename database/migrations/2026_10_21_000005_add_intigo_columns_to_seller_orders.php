<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One seller_order = one Intigo parcel.
 *
 *   parcel_size            S | L | XL sent as package_size (admin may override before sending)
 *   parcel_size_computed   largest item size at checkout
 *   intigo_nid             Intigo's parcel id (unique: one parcel per seller_order)
 *   intigo_sync_status     pending | sent | failed (null = not handed to Intigo)
 *   intigo_error           readable reason of the last failure
 *   intigo_status_code / _label / _at   last carrier status (never moves backwards)
 *   intigo_sent_at, intigo_district_fallback / _district_name (district guessed by Intigo)
 *   intigo_attempts        create attempts (a retry first looks our cid up)
 *   intigo_locked_at       a send in progress (claim; stale after config('intigo.send.lock_seconds'))
 *   intigo_attention       something the admin must look at (cancelled past pickup…)
 *   intigo_previous_nids   parcels cancelled at Intigo before a re-send
 *
 * users.intigo_pickup_index / _label: the seller's pickup address at Intigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->enum('parcel_size', ['S', 'L', 'XL'])->default('S')->after('status');
            $table->enum('parcel_size_computed', ['S', 'L', 'XL'])->nullable()->after('parcel_size');
            $table->string('intigo_nid', 32)->nullable()->unique()->after('carrier_status_at');
            $table->string('intigo_sync_status', 10)->nullable()->after('intigo_nid');
            $table->text('intigo_error')->nullable()->after('intigo_sync_status');
            $table->unsignedSmallInteger('intigo_status_code')->nullable()->after('intigo_error');
            $table->string('intigo_status_label', 120)->nullable()->after('intigo_status_code');
            $table->timestamp('intigo_status_at')->nullable()->after('intigo_status_label');
            $table->timestamp('intigo_sent_at')->nullable()->after('intigo_status_at');
            $table->boolean('intigo_district_fallback')->nullable()->after('intigo_sent_at');
            $table->string('intigo_district_name', 120)->nullable()->after('intigo_district_fallback');
            $table->unsignedTinyInteger('intigo_attempts')->default(0)->after('intigo_district_name');
            $table->timestamp('intigo_locked_at')->nullable()->after('intigo_attempts');
            $table->string('intigo_attention', 255)->nullable()->after('intigo_locked_at');
            $table->json('intigo_previous_nids')->nullable()->after('intigo_attention');
            $table->index('intigo_sync_status');
        });

        DB::table('seller_orders')->update(['parcel_size_computed' => 'S']);

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('intigo_pickup_index')->nullable();
            $table->string('intigo_pickup_label', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['intigo_pickup_index', 'intigo_pickup_label']));
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropIndex(['intigo_sync_status']);
            $table->dropUnique(['intigo_nid']);
            $table->dropColumn([
                'parcel_size', 'parcel_size_computed', 'intigo_nid', 'intigo_sync_status', 'intigo_error',
                'intigo_status_code', 'intigo_status_label', 'intigo_status_at', 'intigo_sent_at',
                'intigo_district_fallback', 'intigo_district_name', 'intigo_attempts', 'intigo_locked_at',
                'intigo_attention', 'intigo_previous_nids',
            ]);
        });
    }
};
