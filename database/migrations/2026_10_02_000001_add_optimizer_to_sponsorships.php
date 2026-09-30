<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Output of the daily ad optimizer (ads:optimize) per campaign:
 *   { tips: [{code, params}], placement_weights: {placement: 0..1}, checked_at, notified: {code: date} }
 * placement_weights lowers a placement that keeps getting clicks without orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorships', function (Blueprint $table) {
            $table->json('optimizer')->nullable()->after('placements');
        });
    }

    public function down(): void
    {
        Schema::table('sponsorships', function (Blueprint $table) {
            $table->dropColumn('optimizer');
        });
    }
};
