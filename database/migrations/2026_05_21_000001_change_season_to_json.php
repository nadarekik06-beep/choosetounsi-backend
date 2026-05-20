<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
        // Step 1 — drop the old index on the string column
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['season']);
        });

        // Step 2 — rename old column to a temp name so we can migrate data
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('season', 'season_old');
        });

        // Step 3 — add the new JSON column
        Schema::table('products', function (Blueprint $table) {
            $table->json('season')
                ->nullable()
                ->after('season_old')
                ->comment('Seller-declared seasons (array). Used by AI sales predictor.');
        });

        // Step 4 — migrate existing string values → JSON arrays
        DB::table('products')->orderBy('id')->chunk(500, function ($products) {
            foreach ($products as $product) {
                $old = $product->season_old ?? 'all_seasons';
                // Wrap the old single value into an array
                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['season' => json_encode([$old])]);
            }
        });

        // Step 5 — drop the old column
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('season_old');
        });
    }

    public function down(): void
    {
        // Reverse: collapse JSON array back to a single string (first value wins)
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('season', 'season_json');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('season', 30)
                ->default('all_seasons')
                ->after('season_json')
                ->comment('Seller-declared product season (legacy single value).');
        });

        DB::table('products')->orderBy('id')->chunk(500, function ($products) {
            foreach ($products as $product) {
                $arr = json_decode($product->season_json ?? '[]', true);
                $val = is_array($arr) && count($arr) > 0 ? $arr[0] : 'all_seasons';
                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['season' => $val]);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('season_json');
            $table->index('season');
        });
    }
};