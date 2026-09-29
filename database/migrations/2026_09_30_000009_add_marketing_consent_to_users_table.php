<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing e-mail consent (opt-in, off by default) and a per-user token for
 * one-click unsubscribe links. New users get a token in User::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('marketing_emails_opt_in')->default(false)->after('locale');
            $table->timestamp('marketing_opt_in_at')->nullable()->after('marketing_emails_opt_in');
            $table->char('unsubscribe_token', 40)->nullable()->after('marketing_opt_in_at');
            $table->timestamp('last_marketing_email_at')->nullable()->after('unsubscribe_token');
        });

        DB::statement("UPDATE users SET unsubscribe_token = LEFT(SHA2(CONCAT(id, '-', UUID(), '-', RAND()), 256), 40) WHERE unsubscribe_token IS NULL");

        Schema::table('users', function (Blueprint $table) {
            $table->unique('unsubscribe_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['unsubscribe_token']);
            $table->dropColumn(['marketing_emails_opt_in', 'marketing_opt_in_at', 'unsubscribe_token', 'last_marketing_email_at']);
        });
    }
};
