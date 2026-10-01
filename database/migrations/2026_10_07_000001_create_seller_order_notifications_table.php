<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (seller sub-order, event) that was notified to the seller.
 * The unique key is what makes order notifications idempotent: retries,
 * double admin clicks and status re-saves can only ever claim it once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->string('event', 32);   // placed | confirmed | cancelled | pickup_reminder
            $table->timestamp('created_at')->nullable();

            $table->unique(['seller_order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_order_notifications');
    }
};
