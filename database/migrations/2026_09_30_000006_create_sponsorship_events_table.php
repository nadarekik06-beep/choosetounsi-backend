<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every impression, click and conversion of a campaign is a row, so the
 * counters on sponsorships are only caches that can be rebuilt. Billing
 * records: a campaign with events can't be hard-deleted (restrictOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsorship_id')->constrained('sponsorships')->restrictOnDelete();
            $table->enum('event', ['impression', 'click', 'conversion', 'conversion_reversed']);
            $table->string('placement', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('session_id', 36)->nullable();
            $table->char('request_id', 36)->comment('Ad response the token came from');
            $table->decimal('cost', 12, 3)->default(0)->comment('Total charged for this click');
            $table->decimal('credit_cost', 12, 3)->default(0)->comment('Part of cost paid with free credit');
            $table->boolean('billable')->default(false);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('revenue', 12, 3)->nullable();
            $table->unsignedBigInteger('click_event_id')->nullable()->comment('conversion → the click it is attributed to');
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sponsorship_id', 'event', 'created_at'], 'idx_sp_events_campaign');
            $table->index(['user_id', 'event', 'created_at'], 'idx_sp_events_user');
            $table->index(['session_id', 'event', 'created_at'], 'idx_sp_events_session');
            $table->unique(['sponsorship_id', 'order_id', 'event'], 'uq_sp_events_conversion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_events');
    }
};
