<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds onboarding_completed flag to users.
 * Once a user completes the /onboarding flow, this is set to true
 * so the redirect never triggers again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('onboarding_completed')
                  ->default(false)
                  ->after('is_approved')
                  ->comment('True once the user has completed the preference onboarding flow');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed');
        });
    }
};