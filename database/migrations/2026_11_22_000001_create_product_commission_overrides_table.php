<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-controlled special commission on ONE product (all its variants).
 *
 *   type  fixed_rate        value = the final commission % (no plan reduction on top)
 *         reduction_points  value = points subtracted from the rate that would otherwise apply
 *
 * "One active override per product at a time" is enforced in code, inside a
 * transaction holding a row lock on the product (works on MariaDB 10.4 and 10.11,
 * no generated column needed). Rows are soft-deleted: orders only keep the id.
 * Purely additive: nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_commission_overrides')) {
            Schema::create('product_commission_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 20);                       // fixed_rate | reduction_points
                $table->decimal('value', 5, 2);                   // 0.00 – 100.00
                $table->timestamp('starts_at')->nullable();       // null = from now
                $table->timestamp('ends_at')->nullable();         // null = no end
                $table->text('note')->nullable();                 // internal, admin only
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('granted_notified_at')->nullable();
                $table->timestamp('reminder_sent_at')->nullable();
                $table->timestamp('ended_notified_at')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index(['product_id', 'is_active']);
                $table->index(['seller_id', 'is_active']);
                $table->index(['is_active', 'ends_at']);
            });
        }

        if (!Schema::hasTable('product_commission_override_logs')) {
            Schema::create('product_commission_override_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('override_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('seller_id')->nullable()->index();
                $table->unsignedBigInteger('actor_id')->nullable();         // null = system (scheduler)
                $table->string('actor_role', 20)->default('system');         // admin | system
                $table->string('action', 30);                                // created | updated | ended | deleted | expired
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // Snapshot on the order line: which override priced it, and what the rate
        // would have been without it. NO foreign key: deleting an override must never
        // alter (or be blocked by) an existing order.
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'commission_override_id')) {
                $table->unsignedBigInteger('commission_override_id')->nullable()->after('commission_source')->index();
            }
            if (!Schema::hasColumn('order_items', 'commission_normal_rate')) {
                $table->decimal('commission_normal_rate', 5, 2)->nullable()->after('commission_override_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            foreach (['commission_normal_rate', 'commission_override_id'] as $c) {
                if (Schema::hasColumn('order_items', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::dropIfExists('product_commission_override_logs');
        Schema::dropIfExists('product_commission_overrides');
    }
};
