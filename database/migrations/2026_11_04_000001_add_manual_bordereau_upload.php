<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual bordereau: the admin uploads the PDF (downloaded from the Intigo dashboard)
 * for each seller_order; the seller is notified, then prints it. Additive only.
 *
 * seller_orders (the current file is still bordereau_path + the download counters)
 *   bordereau_source              manual | intigo (null = no PDF)
 *   bordereau_upload_id           current bordereau_uploads row (manual)
 *   bordereau_uploaded_at / _by   last upload
 *   bordereau_original_name       file name as uploaded
 *   bordereau_seller_notified_at  the seller's bell entry was saved for the current file
 *   bordereau_notify_error        why the notification failed (admin can resend)
 *
 * bordereau_uploads: every file ever uploaded (replaced / removed ones are kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->string('bordereau_source', 10)->nullable();
            $table->unsignedBigInteger('bordereau_upload_id')->nullable();
            $table->timestamp('bordereau_uploaded_at')->nullable();
            $table->unsignedBigInteger('bordereau_uploaded_by')->nullable();
            $table->string('bordereau_original_name', 255)->nullable();
            $table->timestamp('bordereau_seller_notified_at')->nullable();
            $table->string('bordereau_notify_error', 255)->nullable();
        });

        Schema::create('bordereau_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('original_name', 255)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['seller_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bordereau_uploads');
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn([
                'bordereau_source', 'bordereau_upload_id', 'bordereau_uploaded_at', 'bordereau_uploaded_by',
                'bordereau_original_name', 'bordereau_seller_notified_at', 'bordereau_notify_error',
            ]);
        });
    }
};
