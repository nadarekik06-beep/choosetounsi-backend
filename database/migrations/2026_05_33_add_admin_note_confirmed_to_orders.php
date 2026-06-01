<?php
// database/migrations/2026_06_01_000001_add_admin_note_confirmed_to_orders.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('admin_note')->nullable()->after('notes');
            $table->timestamp('confirmed_at')->nullable()->after('admin_note');
        });

        // Add 'confirmed' to the status ENUM
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'pending','confirmed','completed','delivered',
            'out_for_delivery','cancelled','refunded'
        ) NOT NULL DEFAULT 'pending'");

        // Add 'confirmed' to seller_orders status ENUM too
        DB::statement("ALTER TABLE seller_orders MODIFY COLUMN status ENUM(
            'pending','confirmed','completed','delivered',
            'out_for_delivery','cancelled','refunded'
        ) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['admin_note', 'confirmed_at']);
        });

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'pending','processing','completed','delivered',
            'out_for_delivery','cancelled','refunded'
        ) NOT NULL DEFAULT 'pending'");

        DB::statement("ALTER TABLE seller_orders MODIFY COLUMN status ENUM(
            'pending','processing','completed','delivered',
            'out_for_delivery','cancelled','refunded'
        ) NOT NULL DEFAULT 'pending'");
    }
};