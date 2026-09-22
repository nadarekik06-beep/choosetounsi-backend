<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('subtotal')
                ->constrained('coupons')->nullOnDelete();
            // Denormalized snapshot — survives if the coupon is later edited/deleted.
            $table->string('coupon_code')->nullable()->after('coupon_id');
            $table->decimal('discount_amount', 10, 3)->default(0)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'discount_amount']);
        });
    }
};
