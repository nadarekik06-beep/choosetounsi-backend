<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest checkout (order without an account). Additive only:
 *
 *   orders.user_id, carts.user_id, coupon_redemptions.user_id → nullable
 *     (foreign keys kept; existing rows untouched)
 *   orders: is_guest, guest_name, guest_phone, guest_email, tracking_token
 *   carts:  guest_token (the browser's guest cart)
 *   coupon_redemptions: guest_phone ("once per customer" for guests)
 *
 * down() drops the new columns only. user_id stays nullable: making it
 * NOT NULL again would fail (or lose data) once guest rows exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'carts', 'coupon_redemptions'] as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `user_id` BIGINT UNSIGNED NULL");
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'is_guest')) {
                $table->boolean('is_guest')->default(false)->after('user_id');
                $table->string('guest_name', 120)->nullable()->after('is_guest');
                $table->string('guest_phone', 20)->nullable()->after('guest_name');
                $table->string('guest_email', 191)->nullable()->after('guest_phone');
                $table->string('tracking_token', 64)->nullable()->after('guest_email');

                $table->index('is_guest');
                $table->index('guest_phone');
                $table->index('guest_email');
                $table->unique('tracking_token');
            }
        });

        Schema::table('carts', function (Blueprint $table) {
            if (!Schema::hasColumn('carts', 'guest_token')) {
                $table->string('guest_token', 64)->nullable()->after('user_id');
                $table->index('guest_token');
            }
        });

        Schema::table('coupon_redemptions', function (Blueprint $table) {
            if (!Schema::hasColumn('coupon_redemptions', 'guest_phone')) {
                $table->string('guest_phone', 20)->nullable()->after('user_id');
                $table->index('guest_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            if (Schema::hasColumn('coupon_redemptions', 'guest_phone')) {
                $table->dropIndex(['guest_phone']);
                $table->dropColumn('guest_phone');
            }
        });
        Schema::table('carts', function (Blueprint $table) {
            if (Schema::hasColumn('carts', 'guest_token')) {
                $table->dropIndex(['guest_token']);
                $table->dropColumn('guest_token');
            }
        });
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'is_guest')) {
                $table->dropUnique(['tracking_token']);
                $table->dropIndex(['is_guest']);
                $table->dropIndex(['guest_phone']);
                $table->dropIndex(['guest_email']);
                $table->dropColumn(['is_guest', 'guest_name', 'guest_phone', 'guest_email', 'tracking_token']);
            }
        });
    }
};
