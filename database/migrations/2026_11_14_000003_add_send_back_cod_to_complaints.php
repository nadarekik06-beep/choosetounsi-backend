<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispute "send back" (returns policy v2): the COD amount of the parcel back to the client
 * (the refund already paid + the delivery fee, editable by the admin) and the date the
 * client refused it (the item goes back to the seller, the admin decides again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->decimal('sent_back_cod', 10, 3)->nullable();
            $table->timestamp('sent_back_refused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn(['sent_back_cod', 'sent_back_refused_at']);
        });
    }
};
