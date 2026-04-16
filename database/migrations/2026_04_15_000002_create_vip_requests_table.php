<?php
// database/migrations/2026_04_15_000002_create_vip_requests_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores VIP requests submitted by Black Pepper sellers.
 *
 * type    — 'reel' | 'promotion' | 'support'
 * status  — 'pending' | 'in_progress' | 'completed' | 'rejected'
 * message — seller's free-text details
 * admin_note — admin's response / internal note
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vip_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['reel', 'promotion', 'support'])->default('support');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'rejected'])->default('pending');
            $table->text('message');
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vip_requests');
    }
};