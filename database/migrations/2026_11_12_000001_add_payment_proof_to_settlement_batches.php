<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment details + proof of payment (bank transfer receipt / D17 screenshot) on a
 * settlement batch. All columns are nullable: existing batches are untouched and
 * keep working without a proof. The file itself lives on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_batches', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('notes');
            $table->string('payment_reference', 120)->nullable()->after('payment_method');
            $table->date('payment_date')->nullable()->after('payment_reference');
            $table->string('proof_path')->nullable()->after('payment_date');
            $table->string('proof_original_name')->nullable()->after('proof_path');
            $table->string('proof_mime', 60)->nullable()->after('proof_original_name');
            $table->unsignedInteger('proof_size')->nullable()->after('proof_mime');
            $table->unsignedBigInteger('proof_uploaded_by')->nullable()->after('proof_size');
            $table->timestamp('proof_uploaded_at')->nullable()->after('proof_uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_batches', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method', 'payment_reference', 'payment_date', 'proof_path',
                'proof_original_name', 'proof_mime', 'proof_size', 'proof_uploaded_by', 'proof_uploaded_at',
            ]);
        });
    }
};
