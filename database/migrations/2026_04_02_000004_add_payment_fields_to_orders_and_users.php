<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * FIXED VERSION — 2026_04_02_000004_add_payment_fields_to_orders_and_users
 *
 * WHY THE ORIGINAL FAILED:
 *   MySQL error 1265 "Data truncated" means existing rows in orders.payment_method
 *   contain a value that is NOT in the new ENUM list ('cod','card','d17','wallet').
 *   MySQL refuses to silently discard or coerce those values.
 *
 * FIX:
 *   Before altering the ENUM, we UPDATE all existing rows to 'cod'
 *   (the safe default for any order that didn't have a payment method set).
 *   NULL values are left alone — the column stays nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── STEP 1: Normalize existing payment_method values ─────────────────
        //
        // Any value that isn't already a valid ENUM member gets set to 'cod'.
        // This covers: empty string '', unknown strings like 'cash', NULL is fine.
        //
        // We use DB::statement (raw SQL) because DB::table()->update() would
        // do a full table scan with Eloquent overhead — raw is cleaner here.
        //
        DB::statement("
            UPDATE orders
            SET payment_method = 'cod'
            WHERE payment_method IS NOT NULL
              AND payment_method NOT IN ('cod', 'card', 'd17', 'wallet')
        ");

        // ── STEP 2: Now safely alter the column to ENUM ───────────────────────
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN payment_method
            ENUM('cod','card','d17','wallet')
            NULL
            DEFAULT 'cod'
        ");

        // ── STEP 3: Add Stripe and D17 columns ───────────────────────────────
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'stripe_payment_intent_id')) {
                $table->string('stripe_payment_intent_id')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('orders', 'd17_reference')) {
                $table->string('d17_reference', 100)->nullable()->after('stripe_payment_intent_id');
            }
        });

        // ── STEP 4: Add wallet_balance to users ───────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'wallet_balance')) {
                $table->decimal('wallet_balance', 12, 3)->default(0)->after('avatar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('orders', 'stripe_payment_intent_id')) $cols[] = 'stripe_payment_intent_id';
            if (Schema::hasColumn('orders', 'd17_reference'))             $cols[] = 'd17_reference';
            if (!empty($cols)) $table->dropColumn($cols);
        });

        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN payment_method VARCHAR(50) NULL DEFAULT NULL
        ");

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'wallet_balance')) {
                $table->dropColumn('wallet_balance');
            }
        });
    }
};