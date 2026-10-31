<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchanges come back (Intigo: the courier delivers the replacement and
 * collects the old item in the same trip).
 *
 * complaints
 *   fee_size              largest parcel size of the returned lines (one parcel = one Intigo bill)
 *   exchange_fee          the size's exchange fee, frozen at request (return_shipping_fee
 *                         is now the size's after-sale return fee, frozen the same way)
 *   fee_exceeds_refund    client-paid return fee ≥ the items' value: refund 0, flagged to the admin
 *   intigo_exchange_*     the exchange parcel at Intigo (nid, sync state, last carrier status)
 *   exchange_* dates      in transit, exchanged (replacement delivered), failed, old item received
 *
 * complaint_items: the replacement variant chosen by the client and its stock hold.
 *
 * complaint_costs: what Intigo bills us for each after-sale parcel and what we
 * recovered (client refund deduction, client cash on the exchange, seller
 * settlement): the finance section's real margin on returns and exchanges.
 *
 * Legacy exchange complaints (resolution_type = exchange, status closed) are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('fee_size', 3)->nullable()->after('return_shipping_fee');
            $table->decimal('exchange_fee', 8, 3)->default(0)->after('fee_size');
            $table->boolean('fee_exceeds_refund')->default(false)->after('exchange_fee');

            $table->string('intigo_exchange_nid', 32)->nullable()->index();
            $table->string('exchange_sync_status', 20)->nullable();    // sending | sent | manual | needs_check | failed | cancelled
            $table->string('exchange_sync_error', 500)->nullable();
            $table->unsignedSmallInteger('intigo_exchange_status_code')->nullable();
            $table->string('intigo_exchange_status_label', 120)->nullable();
            $table->timestamp('intigo_exchange_status_at')->nullable();
            $table->timestamp('exchange_shipped_at')->nullable();
            $table->timestamp('exchanged_at')->nullable();
            $table->timestamp('exchange_failed_at')->nullable();
            $table->timestamp('old_item_received_at')->nullable();
        });

        Schema::table('complaint_items', function (Blueprint $table) {
            $table->unsignedBigInteger('replacement_variant_id')->nullable()->after('order_item_id');
            $table->string('replacement_label', 191)->nullable()->after('replacement_variant_id');
            $table->timestamp('replacement_reserved_at')->nullable()->after('restocked_at');
            $table->timestamp('replacement_released_at')->nullable()->after('replacement_reserved_at');
        });

        Schema::create('complaint_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            $table->string('kind', 20);                            // return | exchange | exchange_failed
            $table->string('size', 3)->nullable();
            $table->decimal('intigo_cost', 10, 3)->default(0);     // what Intigo bills us
            $table->decimal('recovered_client', 10, 3)->default(0); // refund deduction or cash collected on the exchange
            $table->decimal('recovered_seller', 10, 3)->default(0); // off the seller's next settlement
            $table->string('payer', 10)->nullable();               // client | seller
            $table->string('intigo_nid', 32)->nullable();
            $table->timestamp('booked_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['complaint_id', 'kind', 'intigo_nid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_costs');
        Schema::table('complaint_items', function (Blueprint $table) {
            $table->dropColumn(['replacement_variant_id', 'replacement_label', 'replacement_reserved_at', 'replacement_released_at']);
        });
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropIndex(['intigo_exchange_nid']);
            $table->dropColumn([
                'fee_size', 'exchange_fee', 'fee_exceeds_refund', 'intigo_exchange_nid', 'exchange_sync_status',
                'exchange_sync_error', 'intigo_exchange_status_code', 'intigo_exchange_status_label', 'intigo_exchange_status_at',
                'exchange_shipped_at', 'exchanged_at', 'exchange_failed_at', 'old_item_received_at',
            ]);
        });
    }
};
