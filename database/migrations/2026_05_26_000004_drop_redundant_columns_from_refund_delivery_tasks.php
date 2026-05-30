<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: drop_redundant_columns_from_refund_delivery_tasks
 *
 * Drops all snapshot columns that are fully resolvable via JOINs:
 *
 *   order_id              → complaint.order_id
 *   seller_phone          → complaint.seller.sellerApplication.phone_number
 *   seller_wilaya         → complaint.seller.sellerApplication.wilaya
 *   seller_city           → complaint.seller.sellerApplication.city
 *   items_summary         → complaint.order.items
 *   complaint_type        → complaint.complaint_type
 *   complaint_description → complaint.description
 *   complaint_image_url   → complaint.image_url (Storage::url(complaint.image_path))
 *
 * Kept:
 *   complaint_id   → the only FK needed (everything chains from here)
 *   seller_id      → kept for direct index/filtering on the task level
 *   status, delivery_guy_id, assigned_by, assigned_at,
 *   picked_up_at, completed_at, notes → operational columns, nothing to remove
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_delivery_tasks', function (Blueprint $table) {
            // Drop FK constraint on order_id before dropping the column
            $table->dropForeign(['order_id']);

            $table->dropColumn([
                'order_id',
                'seller_phone',
                'seller_wilaya',
                'seller_city',
                'items_summary',
                'complaint_type',
                'complaint_description',
                'complaint_image_url',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('refund_delivery_tasks', function (Blueprint $table) {
            $table->foreignId('order_id')
                  ->nullable()
                  ->after('complaint_id')
                  ->constrained('orders')
                  ->cascadeOnDelete();

            $table->string('seller_phone', 30)->nullable()->after('seller_id');
            $table->string('seller_wilaya', 100)->nullable()->after('seller_phone');
            $table->string('seller_city', 100)->nullable()->after('seller_wilaya');
            $table->json('items_summary')->nullable()->after('seller_city');
            $table->string('complaint_type', 50)->nullable()->after('items_summary');
            $table->text('complaint_description')->nullable()->after('complaint_type');
            $table->string('complaint_image_url', 500)->nullable()->after('complaint_description');
        });
    }
};