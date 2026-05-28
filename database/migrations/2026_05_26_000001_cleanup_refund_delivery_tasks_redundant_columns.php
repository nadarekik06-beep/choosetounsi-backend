<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop only the columns that are directly available via JOIN:
 *   customer_name    → orders.user.name
 *   customer_phone   → orders.phone
 *   customer_wilaya  → orders.wilaya
 *   customer_address → orders.address
 *   seller_name      → users.name  (via seller_id)
 *   seller_business_name → seller_applications.business_name
 *
 * Kept (no clean single-join equivalent or useful snapshot):
 *   seller_phone, seller_wilaya, seller_city  → seller_applications (kept for delivery guy convenience)
 *   items_summary, complaint_type, complaint_description, complaint_image_url → snapshots
 *   order_id, seller_id → FK references kept
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_delivery_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'customer_name',
                'customer_phone',
                'customer_wilaya',
                'customer_address',
                'seller_name',
                'seller_business_name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('refund_delivery_tasks', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->after('seller_id');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->string('customer_wilaya')->nullable()->after('customer_phone');
            $table->string('customer_address')->nullable()->after('customer_wilaya');
            $table->string('seller_name')->nullable()->after('customer_address');
            $table->string('seller_business_name')->nullable()->after('seller_name');
        });
    }
};