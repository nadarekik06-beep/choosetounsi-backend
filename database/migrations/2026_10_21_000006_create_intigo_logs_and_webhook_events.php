<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * intigo_logs             every request to Intigo and its answer (API key redacted)
 * intigo_webhook_events   every carrier status received (webhook, poll, simulation),
 *                         unique on (nid, new_status, event_timestamp): Intigo retries
 *                         a webhook up to 3 times, a duplicate is stored once.
 *                         Also the parcel's carrier status history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intigo_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_order_id')->nullable()->constrained('seller_orders')->nullOnDelete();
            $table->string('mode', 8);                       // fake | live
            $table->string('method', 8);
            $table->string('endpoint', 191);
            $table->unsignedSmallInteger('http_status')->nullable();   // null = no answer (timeout…)
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('created_at');
            $table->index(['endpoint', 'created_at']);
        });

        Schema::create('intigo_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('nid', 32);
            $table->unsignedSmallInteger('new_status');
            $table->unsignedSmallInteger('old_status')->nullable();
            $table->string('event_timestamp', 40);
            $table->string('event_type', 40)->nullable();
            $table->string('status_label', 120)->nullable();
            $table->string('source', 10);                   // webhook | poll | simulate
            $table->foreignId('seller_order_id')->nullable()->constrained('seller_orders')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 255)->nullable();      // what it did (moved to …, ignored: …)
            $table->timestamp('created_at')->nullable();
            $table->unique(['nid', 'new_status', 'event_timestamp'], 'intigo_events_dedupe');
            $table->index(['seller_order_id', 'created_at']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intigo_webhook_events');
        Schema::dropIfExists('intigo_logs');
    }
};
