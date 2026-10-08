<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complaints become returns: one lifecycle, one resolution (return + refund).
 *
 *   requested → seller_accepted | seller_rejected (→ escalated by the client)
 *             → admin_approved → pickup_scheduled → picked_up
 *             → returned_to_seller (received & inspected) → refunded
 *   terminal: rejected | cancelled | closed (legacy exchange, read-only)
 *
 * - complaints.status becomes a string (the old enum is mapped below);
 * - complaint_items: the returned lines with their quantity, money as paid,
 *   condition at reception and restock stamp (idempotent restock);
 * - complaint_events: timeline + audit trail (who, when, what);
 * - order_items.returned_quantity / returned_amount: the line's live figures
 *   are reduced when a return is refunded, so every sales query is net;
 * - seller_orders / orders.return_status: null | partial | full;
 * - seller_adjustments: debit on the next settlement when the seller was
 *   already paid out for a returned sale (history is never edited).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── complaints ─────────────────────────────────────────────────────
        DB::statement("ALTER TABLE complaints MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'requested'");

        Schema::table('complaints', function (Blueprint $table) {
            $table->string('reference', 30)->nullable()->unique()->after('id');
            $table->json('image_paths')->nullable()->after('image_path');
            $table->string('return_scope', 10)->nullable()->after('resolution_type');          // full | partial
            $table->string('shipping_payer', 10)->nullable()->after('return_scope');           // seller | client
            $table->decimal('return_shipping_fee', 8, 3)->default(0)->after('shipping_payer');
            $table->decimal('items_amount', 10, 3)->default(0)->after('return_shipping_fee');  // net paid for the returned units
            $table->decimal('refund_amount', 10, 3)->default(0)->after('items_amount');        // items_amount − client-paid return shipping
            $table->string('refund_method', 20)->nullable()->after('refund_amount');           // wallet | bank_transfer | d17 | original
            $table->string('refund_reference', 100)->nullable()->after('refund_method');
            $table->timestamp('seller_decided_at')->nullable()->after('seller_decision');
            $table->timestamp('escalated_at')->nullable()->after('seller_decided_at');
            $table->text('escalation_note')->nullable()->after('escalated_at');
            $table->unsignedBigInteger('admin_decided_by')->nullable()->after('escalation_note');
            $table->timestamp('admin_decided_at')->nullable()->after('admin_decided_by');
            $table->text('admin_note')->nullable()->after('admin_decided_at');
            $table->timestamp('pickup_scheduled_at')->nullable()->after('admin_note');
            $table->string('pickup_note', 500)->nullable()->after('pickup_scheduled_at');
            $table->timestamp('picked_up_at')->nullable()->after('pickup_note');
            $table->timestamp('received_at')->nullable()->after('picked_up_at');
            $table->unsignedBigInteger('received_by')->nullable()->after('received_at');
            $table->text('reception_note')->nullable()->after('received_by');
            $table->timestamp('refunded_at')->nullable()->after('reception_note');
            $table->unsignedBigInteger('refunded_by')->nullable()->after('refunded_at');
            $table->timestamp('finance_applied_at')->nullable()->after('refunded_by');
        });

        Schema::create('complaint_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            $table->unsignedBigInteger('order_item_id')->index();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 3)->default(0);
            $table->decimal('gross_amount', 10, 3)->default(0);     // unit_price × qty (before coupon)
            $table->decimal('discount_amount', 10, 3)->default(0);  // coupon share
            $table->decimal('net_amount', 10, 3)->default(0);       // what the client actually paid
            $table->decimal('commission_amount', 10, 3)->default(0);
            $table->decimal('seller_amount', 10, 3)->default(0);
            $table->string('condition', 12)->nullable();             // resaleable | damaged
            $table->unsignedInteger('restocked_quantity')->default(0);
            $table->timestamp('restocked_at')->nullable();
            $table->timestamps();
            $table->unique(['complaint_id', 'order_item_id']);
        });

        Schema::create('complaint_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            $table->string('status', 40);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 20)->nullable();   // client | seller | admin | delivery | system
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['complaint_id', 'id']);
        });

        // ── order lines / sub-orders / orders ───────────────────────────────
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
            $table->decimal('returned_amount', 10, 3)->default(0)->after('returned_quantity');
            $table->timestamp('returned_at')->nullable()->after('returned_amount');
        });
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->string('return_status', 10)->nullable()->after('status')->index();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('return_status', 10)->nullable()->after('status')->index();
        });

        // ── finance ─────────────────────────────────────────────────────────
        Schema::create('seller_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->index();
            $table->unsignedBigInteger('seller_order_id')->nullable()->index();
            $table->unsignedBigInteger('complaint_id')->nullable()->index();
            $table->string('type', 30);                         // return_debit | return_shipping
            $table->decimal('amount', 10, 3);                   // negative = owed by the seller
            $table->string('description', 255);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('settlement_batch_id')->nullable()->index();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
        Schema::table('settlement_batches', function (Blueprint $table) {
            $table->decimal('total_adjustments', 12, 3)->default(0)->after('total_seller_payout');
        });

        DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN reason
            ENUM('order_payment','order_refund','admin_top_up','manual_debit','return_refund') NOT NULL");

        $this->mapLegacyComplaints();
    }

    /** Old statuses → the new lifecycle, items backfilled, one event each. */
    private function mapLegacyComplaints(): void
    {
        $now = now();
        $complaints = DB::table('complaints')->get();

        foreach ($complaints as $c) {
            $task = $c->refund_task_id ? DB::table('refund_delivery_tasks')->where('id', $c->refund_task_id)->first() : null;
            $exchange = $c->resolution_type === 'exchange';

            $status = match ($c->status) {
                'pending', 'reviewing'          => 'requested',
                'seller_rejected_pending_admin' => 'escalated',
                'rejected'                      => 'rejected',
                'approved' => match (true) {
                    $c->refund_status === 'completed' => $exchange ? 'closed' : 'refunded',
                    $c->refund_status === 'picked_up' => 'picked_up',
                    $c->refund_status === 'assigned'  => 'pickup_scheduled',
                    default                           => 'admin_approved',
                },
                default => $c->status,
            };

            DB::table('complaints')->where('id', $c->id)->update([
                'status'         => $status,
                'reference'      => 'RET-' . str_pad((string) $c->id, 6, '0', STR_PAD_LEFT),
                'image_paths'    => $c->image_path ? json_encode([$c->image_path]) : null,
                'resolution_type'=> $c->resolution_type ?? 'return_refund',
                'escalated_at'   => $c->status === 'seller_rejected_pending_admin' ? ($c->reviewed_at ?? $c->updated_at) : null,
                'picked_up_at'   => $task->picked_up_at ?? null,
                // a legacy refund already moved stock and money the old way
                'finance_applied_at' => $status === 'refunded' ? ($c->resolved_at ?? $now) : null,
                'refunded_at'        => $status === 'refunded' ? ($c->resolved_at ?? $now) : null,
            ]);

            // Returned lines: the chosen ones, else the shop's lines of the order
            $lines = DB::table('order_items as oi')
                ->leftJoin('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
                ->where('oi.order_id', $c->order_id)
                ->when($c->order_item_ids, fn($q) => $q->whereIn('oi.id', (array) json_decode($c->order_item_ids, true) ?: [0]))
                ->when(!$c->order_item_ids && $c->seller_id, fn($q) => $q->where(fn($w) => $w->where('so.seller_id', $c->seller_id)->orWhereNull('so.id')))
                ->get(['oi.*']);

            $amount = 0.0;
            foreach ($lines as $l) {
                $net = (float) ($l->net_total ?? ((float) $l->total - (float) $l->discount_amount));
                $amount += $net;
                DB::table('complaint_items')->insert([
                    'complaint_id'      => $c->id,
                    'order_item_id'     => $l->id,
                    'quantity'          => (int) $l->quantity,
                    'unit_price'        => (float) ($l->unit_price ?: $l->price),
                    'gross_amount'      => (float) $l->total,
                    'discount_amount'   => (float) $l->discount_amount,
                    'net_amount'        => round($net, 3),
                    'commission_amount' => (float) $l->commission_amount,
                    'seller_amount'     => (float) $l->seller_amount,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }

            $total = DB::table('order_items')->where('order_id', $c->order_id)->count();
            DB::table('complaints')->where('id', $c->id)->update([
                'items_amount'   => round($amount, 3),
                'refund_amount'  => round($amount, 3),
                'return_scope'   => $lines->count() >= $total ? 'full' : 'partial',
                'shipping_payer' => $c->complaint_type === 'other' ? 'client' : 'seller',
                'order_item_ids' => json_encode($lines->pluck('id')->map(fn($v) => (int) $v)->values()->all()),
            ]);

            DB::table('complaint_events')->insert([
                'complaint_id' => $c->id, 'status' => 'requested', 'actor_id' => $c->user_id,
                'actor_role' => 'client', 'note' => null, 'meta' => null, 'created_at' => $c->created_at,
            ]);
            if ($status !== 'requested') {
                DB::table('complaint_events')->insert([
                    'complaint_id' => $c->id, 'status' => $status, 'actor_id' => null, 'actor_role' => 'system',
                    'note' => 'Migrated from the previous complaint flow', 'meta' => null, 'created_at' => $c->updated_at ?? $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN reason
            ENUM('order_payment','order_refund','admin_top_up','manual_debit') NOT NULL");
        Schema::table('settlement_batches', fn(Blueprint $t) => $t->dropColumn('total_adjustments'));
        Schema::dropIfExists('seller_adjustments');
        Schema::table('orders', fn(Blueprint $t) => $t->dropColumn('return_status'));
        Schema::table('seller_orders', fn(Blueprint $t) => $t->dropColumn('return_status'));
        Schema::table('order_items', fn(Blueprint $t) => $t->dropColumn(['returned_quantity', 'returned_amount', 'returned_at']));
        Schema::dropIfExists('complaint_events');
        Schema::dropIfExists('complaint_items');

        DB::table('complaints')->whereIn('status', ['requested'])->update(['status' => 'pending']);
        DB::table('complaints')->whereIn('status', ['seller_rejected', 'escalated'])->update(['status' => 'seller_rejected_pending_admin']);
        DB::table('complaints')->whereNotIn('status', ['pending', 'seller_rejected_pending_admin', 'rejected'])->update(['status' => 'approved']);
        DB::statement("ALTER TABLE complaints MODIFY COLUMN status
            ENUM('pending','reviewing','approved','seller_rejected_pending_admin','rejected') NOT NULL DEFAULT 'pending'");

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn([
                'reference', 'image_paths', 'return_scope', 'shipping_payer', 'return_shipping_fee', 'items_amount',
                'refund_amount', 'refund_method', 'refund_reference', 'seller_decided_at', 'escalated_at',
                'escalation_note', 'admin_decided_by', 'admin_decided_at', 'admin_note', 'pickup_scheduled_at',
                'pickup_note', 'picked_up_at', 'received_at', 'received_by', 'reception_note', 'refunded_at',
                'refunded_by', 'finance_applied_at',
            ]);
        });
    }
};
