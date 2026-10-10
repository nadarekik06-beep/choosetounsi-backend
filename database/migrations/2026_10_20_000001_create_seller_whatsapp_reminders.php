<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual WhatsApp seller notifications (App\Services\Orders\WhatsApp):
 *
 *   users                   whatsapp_number ("216XXXXXXXX", no "+"), preferred_language (fr | ar)
 *   seller_orders           prepared_at / prepared_by: the seller marked the parcel prepared
 *                           overdue_at: set when reminder 2 is sent; from then on, still
 *                           not prepared = overdue (the admin calls the seller)
 *   seller_order_reminders  attempt 0 = initial notice, 1 and 2 = reminders;
 *                           pending → due (due_at passed) → sent | cancelled
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_number', 20)->nullable()->after('phone');
            $table->string('preferred_language', 2)->default('fr')->after('locale');
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->timestamp('prepared_at')->nullable()->after('status');
            $table->foreignId('prepared_by')->nullable()->after('prepared_at')->constrained('users')->nullOnDelete();
            $table->timestamp('overdue_at')->nullable()->after('prepared_by');
        });

        Schema::create('seller_order_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt');                 // 0 initial, 1, 2
            // nullable: MySQL would give a NOT NULL timestamp ON UPDATE CURRENT_TIMESTAMP
            $table->timestamp('due_at')->nullable();
            $table->string('status', 16)->default('pending');       // pending | due | sent | cancelled
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seller_order_id', 'attempt']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_order_reminders');

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prepared_by');
            $table->dropColumn(['prepared_at', 'overdue_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'preferred_language']);
        });
    }
};
