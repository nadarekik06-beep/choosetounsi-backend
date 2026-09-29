<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clicks are always logged, but duplicates, bots and the seller's own clicks
 * don't count in CTR / click totals. The flag lets ads:reconcile-stats rebuild
 * counters exactly from events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorship_events', function (Blueprint $table) {
            $table->boolean('countable')->default(true)->after('billable');
        });
    }

    public function down(): void
    {
        Schema::table('sponsorship_events', function (Blueprint $table) {
            $table->dropColumn('countable');
        });
    }
};
