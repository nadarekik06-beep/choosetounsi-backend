<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad wallet ledger. `amount` is the signed total; `credit_amount` is the signed
 * part that moved on the free credit (amount − credit_amount = paid balance), so
 * revenue reports can separate real money from plan credit.
 *
 * Click charges are rolled up: one click_charge row per campaign per Africa/Tunis
 * day (UNIQUE sponsorship_id + type + rollup_date), updated as clicks arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['top_up', 'monthly_credit', 'click_charge', 'refund', 'admin_adjust', 'credit_expiry']);
            $table->decimal('amount', 12, 3);
            $table->decimal('credit_amount', 12, 3)->default(0);
            $table->decimal('balance_after', 12, 3);
            $table->decimal('credit_after', 12, 3);
            $table->foreignId('sponsorship_id')->nullable()->constrained('sponsorships')->nullOnDelete();
            $table->date('rollup_date')->nullable()->comment('Africa/Tunis day of a rolled-up click_charge row');
            $table->string('reference')->nullable()->comment('Gateway reference, top-up id, …');
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['seller_id', 'created_at']);
            $table->unique(['sponsorship_id', 'type', 'rollup_date'], 'uq_ad_tx_rollup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_wallet_transactions');
    }
};
