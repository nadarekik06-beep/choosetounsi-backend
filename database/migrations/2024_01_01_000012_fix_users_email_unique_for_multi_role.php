<?php
// database/migrations/xxxx_fix_users_email_unique_for_multi_role.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixUsersEmailUniqueForMultiRole extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the global unique index on email alone
            $table->dropUnique('users_email_unique');

            // Add a composite unique: same email CAN exist with different roles,
            // but the same (email + role) combination must be unique
            $table->unique(['email', 'role'], 'users_email_role_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_role_unique');
            $table->unique('email', 'users_email_unique');
        });
    }
}