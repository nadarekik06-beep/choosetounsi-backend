<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer profile: structured name, phone, birth date, gender, notification
 * preferences, and whether the account has a password the owner chose
 * (Google sign-ups get a random one they never saw).
 *
 * Purely additive — existing rows keep working; `name` stays the display name
 * and is rebuilt from first/last whenever the profile is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable()->after('name');
            $table->string('last_name', 60)->nullable()->after('first_name');
            $table->string('phone', 20)->nullable()->after('email');
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->enum('gender', ['male', 'female'])->nullable()->after('date_of_birth');
            // Existing accounts may well have chosen a password (Google can be linked
            // to an e-mail account later), so they default to true and keep the
            // "current password" check. Only new Google sign-ups start at false.
            $table->boolean('has_password')->default(true)->after('password');
            $table->json('notification_preferences')->nullable()->after('marketing_opt_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'phone', 'date_of_birth', 'gender',
                'has_password', 'notification_preferences',
            ]);
        });
    }
};
