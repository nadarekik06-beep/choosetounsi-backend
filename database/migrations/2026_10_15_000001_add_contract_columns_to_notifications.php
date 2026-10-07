<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification contract (config/notifications.php):
 *   - notifications.audience / category, copied from the payload so the bell can
 *     filter buyer / seller / admin rows with an index instead of JSON;
 *   - notification_dispatches: one row per (user, dedupe key) — a retry or a double
 *     status update can never send the same notification twice;
 *   - review_prompts.notified_at: the "rate your purchase" reminder goes out once.
 *
 * Existing rows are filled by `php artisan notifications:normalize`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('audience', 16)->nullable()->after('notifiable_id');
            $table->string('category', 24)->nullable()->after('audience');
            $table->index(['notifiable_type', 'notifiable_id', 'audience', 'read_at'], 'notifiable_audience_read_idx');
        });

        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('dedupe_key', 191);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index('created_at');
        });

        Schema::table('review_prompts', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('review_prompts', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });

        Schema::dropIfExists('notification_dispatches');

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifiable_audience_read_idx');
            $table->dropColumn(['audience', 'category']);
        });
    }
};
