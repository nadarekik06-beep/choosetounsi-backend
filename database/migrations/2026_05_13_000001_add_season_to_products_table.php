<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Canonical season values — enforced at DB and validation layer
    // The AI engine reads this column directly; no guessing ever happens.
    public const SEASONS = [
        'all_seasons',
        'summer',
        'winter',
        'spring',
        'autumn',
        'ramadan',
        'eid_al_fitr',
        'eid_al_adha',
        'back_to_school',
        'new_year',
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('season', 30)
                ->default('all_seasons')
                ->after('is_pack')
                ->comment('Seller-declared product season. Used by AI sales predictor. Never guessed.');

            $table->index('season');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['season']);
            $table->dropColumn('season');
        });
    }
};