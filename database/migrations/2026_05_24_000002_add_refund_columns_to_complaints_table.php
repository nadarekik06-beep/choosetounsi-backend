<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: add refund tracking columns to the complaints table.
 *
 * New columns:
 *   refund_status    — tracks the refund delivery lifecycle (NULL until complaint approved)
 *   refund_task_id   — FK to refund_delivery_tasks (NULL until task is created)
 *
 * Both columns are nullable so that existing complaint rows are unaffected.
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {

            // Refund delivery lifecycle status.
            // NULL  → complaint not yet approved, or approval didn't trigger a refund
            // pending   → task created, waiting for delivery admin to assign
            // assigned  → delivery guy assigned, going to customer
            // picked_up → delivery guy picked up the item from the customer
            // completed → delivery guy marked the refund as complete
            $table->enum('refund_status', ['pending', 'assigned', 'picked_up', 'completed'])
                  ->nullable()
                  ->default(null)
                  ->after('resolved_at');

            // FK to refund_delivery_tasks — set when task is auto-created on approval.
            // We don't add a formal FK constraint here because refund_delivery_tasks
            // is created in the next migration. The model relationship handles integrity.
            $table->unsignedBigInteger('refund_task_id')
                  ->nullable()
                  ->default(null)
                  ->after('refund_status');

            $table->index('refund_status');
            $table->index('refund_task_id');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['refund_status']);
            $table->dropIndex(['refund_task_id']);
            $table->dropColumn(['refund_status', 'refund_task_id']);
        });
    }
};