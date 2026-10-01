<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Season / Occasion moves from products.season (JSON) to the product_occasions
 * pivot, which MariaDB can index (it cannot index inside a JSON array).
 * products.season is dropped by the next migration, once nothing reads it.
 *
 * Multi-pack: is_pack gets an index plus pack_quantity / pack_contents.
 * Existing packs keep pack_quantity null — the seller sets it on the next save.
 */
return new class extends Migration
{
    private const MAP = [
        'all_seasons'    => 'all_season',
        'summer'         => 'summer',
        'winter'         => 'winter',
        'ramadan'        => 'ramadan',
        'eid_al_fitr'    => 'aid',
        'eid_al_adha'    => 'aid',
        'back_to_school' => 'back_to_school',
        // spring, autumn, new_year: no equivalent, dropped
    ];

    public function up(): void
    {
        Schema::create('product_occasions', function (Blueprint $table) {
            $table->id();   // tooling (demo:purge) walks FK children by id
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('occasion', 20);
            $table->unique(['product_id', 'occasion']);
            $table->index(['occasion', 'product_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('pack_quantity')->nullable()->after('is_pack');
            $table->string('pack_contents', 500)->nullable()->after('pack_quantity');
            $table->index('is_pack');
        });

        DB::table('products')->select('id', 'season')->orderBy('id')->chunk(500, function ($rows) {
            $insert = [];
            foreach ($rows as $row) {
                $old = json_decode((string) $row->season, true);
                $old = is_array($old) ? $old : ($row->season ? [$row->season] : []);
                $new = array_values(array_unique(array_filter(array_map(fn($s) => self::MAP[$s] ?? null, $old))));
                $specific = array_values(array_diff($new, ['all_season']));
                foreach ($specific ?: ['all_season'] as $occasion) {
                    $insert[] = ['product_id' => $row->id, 'occasion' => $occasion];
                }
            }
            DB::table('product_occasions')->insert($insert);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_pack']);
            $table->dropColumn(['pack_quantity', 'pack_contents']);
        });
        Schema::dropIfExists('product_occasions');
    }
};
