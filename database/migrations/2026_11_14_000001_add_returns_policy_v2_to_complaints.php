<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange-first returns policy (v2). Everything is nullable / defaulted: existing
 * complaints keep policy_version NULL and finish with the old flow.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'policy_version',
        'exchange_impossible_at', 'exchange_impossible_by', 'exchange_impossible_reason', 'switched_to_refund_at',
        'item_unused_confirmed',
        'refund_amount_locked', 'refund_locked_at',
        'payout_frozen_at', 'payout_released_at',
        'receive_timeout_notified_at', 'inspection_due_at', 'inspection_result', 'inspection_by', 'inspection_at',
        'inspection_comment', 'inspection_photos', 'inspection_reminder_sent_at', 'inspection_timeout_notified_at',
        'dispute_status', 'dispute_decision', 'dispute_amount', 'dispute_note', 'dispute_decided_by', 'dispute_decided_at',
        'sent_back_nid', 'sent_back_fee',
    ];

    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->unsignedTinyInteger('policy_version')->nullable()->index();
            $table->timestamp('exchange_impossible_at')->nullable();
            $table->unsignedBigInteger('exchange_impossible_by')->nullable();
            $table->string('exchange_impossible_reason', 500)->nullable();
            $table->timestamp('switched_to_refund_at')->nullable();
            $table->boolean('item_unused_confirmed')->nullable();
            $table->decimal('refund_amount_locked', 10, 3)->nullable();
            $table->timestamp('refund_locked_at')->nullable();
            $table->timestamp('payout_frozen_at')->nullable();
            $table->timestamp('payout_released_at')->nullable();
            $table->timestamp('receive_timeout_notified_at')->nullable();
            $table->timestamp('inspection_due_at')->nullable();
            $table->string('inspection_result', 12)->nullable();
            $table->unsignedBigInteger('inspection_by')->nullable();
            $table->timestamp('inspection_at')->nullable();
            $table->text('inspection_comment')->nullable();
            $table->json('inspection_photos')->nullable();
            $table->timestamp('inspection_reminder_sent_at')->nullable();
            $table->timestamp('inspection_timeout_notified_at')->nullable();
            $table->string('dispute_status', 12)->nullable();
            $table->string('dispute_decision', 20)->nullable();
            $table->decimal('dispute_amount', 10, 3)->nullable();
            $table->text('dispute_note')->nullable();
            $table->unsignedBigInteger('dispute_decided_by')->nullable();
            $table->timestamp('dispute_decided_at')->nullable();
            $table->string('sent_back_nid', 60)->nullable();
            $table->decimal('sent_back_fee', 8, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['policy_version']);
            $table->dropColumn(self::COLUMNS);
        });
    }
};
