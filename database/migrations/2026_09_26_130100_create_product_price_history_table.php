<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selling-price timeline, per product (variant_id NULL = base price) and per
 * variant (its effective price: override or base). A discount's crossed-out
 * price is the lowest price of the last 30 days (see App\Services\PriceHistory).
 *
 * Seeded with every product's / variant's current price as of its creation date,
 * since earlier changes were never recorded.
 */
class CreateProductPriceHistoryTable extends Migration
{
    public function up()
    {
        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('price', 12, 3);
            $table->string('source', 30)->nullable();     // seed | seller | admin | revert | system
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['product_id', 'variant_id', 'changed_at'], 'pph_lookup_idx');
        });

        $now = now();

        DB::table('products')->select('id', 'price', 'created_at')->orderBy('id')
            ->chunk(500, function ($rows) use ($now) {
                DB::table('product_price_history')->insert($rows->map(fn($p) => [
                    'product_id' => $p->id,
                    'variant_id' => null,
                    'price'      => $p->price ?? 0,
                    'source'     => 'seed',
                    'changed_at' => $p->created_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->select('v.id', 'v.product_id', 'v.price_override', 'v.created_at', 'p.price')
            ->orderBy('v.id')
            ->chunk(500, function ($rows) use ($now) {
                DB::table('product_price_history')->insert($rows->map(fn($v) => [
                    'product_id' => $v->product_id,
                    'variant_id' => $v->id,
                    'price'      => $v->price_override ?? $v->price ?? 0,
                    'source'     => 'seed',
                    'changed_at' => $v->created_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down()
    {
        Schema::dropIfExists('product_price_history');
    }
}
