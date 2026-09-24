<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for everything that happens to a seller
 * subscription: admin actions, seller actions and scheduler events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_subscription_id')->nullable()->constrained('seller_subscriptions')->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            // null = system (scheduler)
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 20)->default('system');   // admin | seller | system
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('plan_change_id')->nullable()->constrained('subscription_plan_changes')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['seller_id', 'created_at']);
            $table->index(['subscription_plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_audit_logs');
    }
};
