<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the old per-row verification columns from the users table.
 *
 * These are no longer needed because unverified registration data is now
 * stored in the cache (pending_reg:{email}) — users are only inserted into
 * the DB after successful email verification.
 *
 * email_verified_at is KEPT because it is still used:
 *   - to mark verified users
 *   - for Google OAuth auto-verification
 *   - as a guard in login()
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop these three columns — they are now redundant.
            // All verification state lives in the cache before DB creation.
            $table->dropColumn([
                'email_verification_code',
                'email_verification_expires_at',
                'email_verification_attempts',
            ]);
        });
    }

    public function down(): void
    {
        // Restore columns in case of rollback
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_verification_code')->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_expires_at')->nullable()->after('email_verification_code');
            $table->unsignedTinyInteger('email_verification_attempts')->default(0)->after('email_verification_expires_at');
        });
    }
};