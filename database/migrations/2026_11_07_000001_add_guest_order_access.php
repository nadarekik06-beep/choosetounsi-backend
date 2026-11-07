<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guests can track and complain with only their phone. Additive only:
 *
 *   complaints.user_id → nullable (foreign key kept, existing rows untouched):
 *     a guest's return has no account; it belongs to its order.
 *   guest_order_tokens: the device tokens that open a guest order, stored as
 *     sha256 hashes only. One per checkout, one more per successful
 *     "order number + phone" lookup (each phone/browser gets its own).
 *     Filled from orders.tracking_token so every link already sent keeps working.
 *
 * down() drops the token table only. complaints.user_id stays nullable:
 * NOT NULL again would fail once guest returns exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `complaints` MODIFY `user_id` BIGINT UNSIGNED NULL');

        if (!Schema::hasTable('guest_order_tokens')) {
            Schema::create('guest_order_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->char('token_hash', 64)->unique();
                $table->string('source', 20)->default('checkout'); // checkout | lookup | legacy
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['order_id', 'id']);
            });
        }

        // Links already sent (confirmation page / e-mail) → their hash
        DB::table('orders')->whereNotNull('tracking_token')->select(['id', 'tracking_token', 'created_at'])
            ->orderBy('id')->chunk(500, function ($orders) {
                $rows = $orders->map(fn ($o) => [
                    'order_id'   => $o->id,
                    'token_hash' => hash('sha256', $o->tracking_token),
                    'source'     => 'legacy',
                    'created_at' => $o->created_at ?? now(),
                ])->all();
                DB::table('guest_order_tokens')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_order_tokens');
    }
};
