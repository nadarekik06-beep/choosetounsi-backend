<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the shopper last chose "Skip for now" on /complete-profile (re-asked after 7 days). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'profile_prompt_skipped_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('profile_prompt_skipped_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'profile_prompt_skipped_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('profile_prompt_skipped_at'));
        }
    }
};
