<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** products.season is replaced by product_occasions (previous migration). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('season');
        });
    }

    /** Best-effort restore: aid → both Eids, wedding_season has no old equivalent. */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('season')->nullable()->after('is_pack');
        });

        $map = [
            'all_season' => ['all_seasons'], 'summer' => ['summer'], 'winter' => ['winter'],
            'ramadan' => ['ramadan'], 'aid' => ['eid_al_fitr', 'eid_al_adha'], 'back_to_school' => ['back_to_school'],
        ];
        DB::table('product_occasions')->orderBy('product_id')->get()->groupBy('product_id')
            ->each(function ($rows, $productId) use ($map) {
                $old = array_values(array_unique(array_merge([], ...$rows->map(fn($r) => $map[$r->occasion] ?? [])->all())));
                DB::table('products')->where('id', $productId)->update(['season' => json_encode($old ?: ['all_seasons'])]);
            });
        DB::table('products')->whereNull('season')->update(['season' => json_encode(['all_seasons'])]);
    }
};
