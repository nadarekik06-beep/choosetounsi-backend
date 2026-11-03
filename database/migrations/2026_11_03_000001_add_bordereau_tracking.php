<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellers download their parcel's Intigo bordereau from the dashboard. Additive only.
 *
 * seller_orders
 *   bordereau_path                 cached PDF on the local disk (private/bordereaux/{id}-{nid}.pdf)
 *   bordereau_first_downloaded_at  first seller download (admins are told once, then)
 *   bordereau_last_downloaded_at   last seller download
 *   bordereau_download_count       seller downloads (admin downloads are not counted)
 *
 * bordereau_downloads: one row per download (seller or admin), the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->string('bordereau_path', 255)->nullable();
            $table->timestamp('bordereau_first_downloaded_at')->nullable();
            $table->timestamp('bordereau_last_downloaded_at')->nullable();
            $table->unsignedInteger('bordereau_download_count')->default(0);
        });

        Schema::create('bordereau_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10)->default('seller');   // seller | admin
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['seller_order_id', 'created_at']);
            $table->index('seller_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bordereau_downloads');
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn(['bordereau_path', 'bordereau_first_downloaded_at', 'bordereau_last_downloaded_at', 'bordereau_download_count']);
        });
    }
};
