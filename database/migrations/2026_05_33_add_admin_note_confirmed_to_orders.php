<?php
// database/migrations/2026_05_33_add_admin_note_confirmed_to_orders.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns only if they don't already exist
        // (safe to run on both fresh and partially-migrated databases)
        if (!Schema::hasColumn('orders', 'admin_note')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('admin_note')->nullable()->after('notes');
            });
        }

        if (!Schema::hasColumn('orders', 'confirmed_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('confirmed_at')->nullable()->after('admin_note');
            });
        }

        // Disable strict mode so MySQL warnings don't abort the ENUM alter
        DB::statement("SET SESSION sql_mode = ''");

        // Add 'confirmed' to the orders status ENUM
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'pending','confirmed','completed','delivered',
            'out_for_delivery','cancelled','refunded'
        ) NOT NULL DEFAULT 'pending'");

        // Add 'confirmed' to seller_orders status ENUM too
        DB::statement("ALTER TABLE seller_orders MODIFY COLUMN status ENUM(
            'pending','confirmed','completed','delivered',
            'out_for_delivery','cancelled','refunded'
        ) NOT NULL DEFAULT 'pending'");

        // Restore strict mode
        DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'admin_note')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('admin_note');
            });
        }

        if (Schema::hasColumn('orders', 'confirmed_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('confirmed_at');
            });
        }

        DB::statement("SET SESSION sql_mode = ''");

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'pending','processing','completed','delivered',
            'out_for_delivery','cancelled','refunded'
        ) NOT NULL DEFAULT 'pending'");

        DB::statement("ALTER TABLE seller_orders MODIFY COLUMN status ENUM(
            'pending','processing','completed','delivered',
            'out_for_delivery','cancelled','refunded'
        ) NOT NULL DEFAULT 'pending'");

        DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
    }
};