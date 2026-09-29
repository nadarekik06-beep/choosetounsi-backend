<?php

namespace Tests\Feature\Ads;

use App\Services\Ads\ReadinessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Boost readiness: blockers (not listed, no sellable stock, no photo, far pricier
 * than similar products) and fixable tips.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/ReadinessTest.php
 */
class ReadinessTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    private ReadinessService $readiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        $this->readiness = app(ReadinessService::class);
    }

    private function codes(array $items): array
    {
        return array_column($items, 'code');
    }

    public function test_a_complete_product_passes_with_full_score(): void
    {
        $r = $this->readiness->check($this->readyProduct($this->seller()));

        $this->assertTrue($r['passes']);
        $this->assertSame(100, $r['score']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame([], $r['tips']);
    }

    public function test_stock_is_the_total_of_active_variants(): void
    {
        $product = $this->readyProduct($this->seller(), null, ['stock' => 10]);
        // Variants exist: the product's own stock no longer counts.
        DB::table('product_variants')->insert([
            ['product_id' => $product->id, 'sku' => 'A-' . $product->id, 'stock' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $product->id, 'sku' => 'B-' . $product->id, 'stock' => 7, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $r = $this->readiness->check($product);
        $this->assertSame(0, $this->readiness->totalStock($product), 'inactive variants are not sellable');
        $this->assertContains('out_of_stock', $this->codes($r['blockers']));
        $this->assertFalse($r['passes']);

        DB::table('product_variants')->where('sku', 'A-' . $product->id)->update(['stock' => 4]);
        $this->assertSame(4, $this->readiness->totalStock($product));
        $this->assertTrue($this->readiness->check($product)['passes']);
    }

    public function test_thin_listings_get_tips_and_a_lower_score(): void
    {
        $product = $this->readyProduct($this->seller(), null, ['description' => 'Nice.'], images: 1);

        $r = $this->readiness->check($product);
        $this->assertContains('add_images', $this->codes($r['tips']));
        $this->assertContains('improve_description', $this->codes($r['tips']));
        $this->assertLessThan(100, $r['score']);
        $this->assertSame('ai_description', collect($r['tips'])->firstWhere('code', 'improve_description')['action']);
    }

    public function test_price_far_above_similar_products_blocks(): void
    {
        $seller   = $this->seller();
        $category = $this->makeCategory();
        foreach ([10, 12, 11, 9] as $price) {
            $this->readyProduct($seller, $category, ['price' => $price]);
        }

        $pricey = $this->readyProduct($seller, $category, ['price' => 40]);
        $r = $this->readiness->check($pricey);
        $this->assertContains('price_far_above_similar', $this->codes($r['blockers']));
        $this->assertEquals(10.5, collect($r['blockers'])->firstWhere('code', 'price_far_above_similar')['params']['median']);

        $abitMore = $this->readyProduct($seller, $category, ['price' => 15]);
        $r = $this->readiness->check($abitMore);
        $this->assertContains('price_above_similar', $this->codes($r['tips']));
        $this->assertTrue($r['passes']);
    }

    public function test_unlisted_product_is_blocked(): void
    {
        $product = $this->readyProduct($this->seller(), null, ['is_active' => false]);
        $this->assertContains('not_listed', $this->codes($this->readiness->check($product)['blockers']));
    }
}
