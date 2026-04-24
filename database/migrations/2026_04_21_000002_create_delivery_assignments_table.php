<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * delivery_assignments
 *
 * Links a seller_order to a delivery_guy.
 * One row per assignment. If re-assigned, the old row is updated (not duplicated).
 *
 * Columns:
 *   seller_order_id   FK → seller_orders.id  (what needs to be delivered)
 *   delivery_guy_id   FK → users.id          (who will deliver it)
 *   assigned_by       FK → users.id          (delivery_admin who assigned it)
 *   assigned_at       timestamp
 *   picked_up_at      nullable timestamp (set when delivery guy marks picked_up)
 *   delivered_at      nullable timestamp (set when delivered)
 *   status            assigned | picked_up | delivered | canceled
 *   notes             optional text from delivery admin
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('seller_order_id')
                  ->constrained('seller_orders')
                  ->onDelete('cascade');

            $table->unsignedBigInteger('delivery_guy_id');
            $table->foreign('delivery_guy_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->unsignedBigInteger('assigned_by');
            $table->foreign('assigned_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->enum('status', ['assigned', 'picked_up', 'delivered', 'canceled'])
                  ->default('assigned');

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // One active assignment per seller_order at a time
            $table->unique('seller_order_id');

            $table->index('delivery_guy_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
    }
};