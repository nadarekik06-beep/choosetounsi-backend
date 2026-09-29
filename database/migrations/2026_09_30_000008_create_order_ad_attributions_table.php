<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order line → the ad click it came from (last billable click within the
 * attribution window). Kept separate from order_items so checkout tables stay
 * untouched. pending at order creation, converted on delivery, reversed on
 * cancel/refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_ad_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('sponsorship_id')->constrained('sponsorships')->restrictOnDelete();
            $table->foreignId('click_event_id')->nullable()->constrained('sponsorship_events')->nullOnDelete();
            $table->decimal('revenue', 12, 3)->default(0);
            $table->enum('status', ['pending', 'converted', 'reversed'])->default('pending');
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['sponsorship_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ad_attributions');
    }
};
