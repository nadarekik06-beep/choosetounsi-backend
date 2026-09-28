<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized per-user (or per-guest-session) interest profile, rebuilt from
 * user_interactions by InterestProfileService. The feed reads this (via cache)
 * instead of re-aggregating raw interactions on every homepage load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_interest_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->char('session_id', 36)->nullable()->unique();
            $table->json('profile');
            $table->unsignedInteger('signal_count')->default(0);
            $table->timestamp('computed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_interest_profiles');
    }
};
