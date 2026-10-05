<?php

namespace Tests\Feature\Shop;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Services\ProductCardImages;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * Storefront card payloads: card_images / card_swatches (image slider) on list endpoints,
 * promo flyers for product grids, and the grid density settings.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Shop/ProductCardImagesTest.php
 */
class ProductCardImagesTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTranslator();
        Cache::flush();
        ProductCardImages::flush();
    }

    /** Cover + 2 gallery shots + 2 colors (2 images each, the second color shares nothing). */
    private function productWithColors(): array
    {
        $seller  = $this->makeUser('seller');
        $product = $this->makeProduct($seller, $this->makeCategory());
        $r = Str::random(5);
        $color = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color', 'name_ar' => 'Color', 'name_fr' => 'Couleur', 'type' => 'color']);
        $red   = AttributeOption::create(['attribute_id' => $color->id, 'value' => "Red $r", 'color_hex' => '#ff0000', 'order' => 1]);
        $blue  = AttributeOption::create(['attribute_id' => $color->id, 'value' => "Blue $r", 'color_hex' => '#0000ff', 'order' => 2]);

        $img = fn (string $path, int $order, ?int $color = null, bool $primary = false) => ProductImage::create([
            'product_id' => $product->id, 'image_path' => "products/$path-$r.jpg", 'order' => $order,
            'is_primary' => $primary, 'color_option_id' => $color,
        ]);
        $img('cover', 0, null, true);
        $img('side', 1);
        $img('back', 2);
        $img('blue-1', 3, $blue->id);     // blue listed first in the images, but red comes first by option order
        $img('blue-2', 4, $blue->id);
        $img('red-1', 5, $red->id);
        $img('red-2', 6, $red->id);

        foreach ([$red, $blue] as $opt) {
            $v = ProductVariant::create(['product_id' => $product->id, 'sku' => "SKU-$r-{$opt->id}", 'stock' => 4, 'is_active' => true]);
            $v->attributeOptions()->attach($opt->id);
        }
        return [$product, $red, $blue, $r];
    }

    public function test_card_images_are_cover_gallery_then_one_image_per_color(): void
    {
        [$product, $red, $blue, $r] = $this->productWithColors();

        $block = ProductCardImages::for($product->id);
        $names = array_map(fn ($u) => basename($u, "-$r.jpg"), $block['card_images']);

        $this->assertSame(['cover', 'side', 'back', 'red-1', 'blue-1'], $names);
        $this->assertSame([$red->id, $blue->id], array_column($block['card_swatches'], 'id'));
        $this->assertSame('#ff0000', $block['card_swatches'][0]['hex']);
        $this->assertStringEndsWith("red-1-$r.jpg", $block['card_swatches'][0]['image']);
    }

    public function test_list_endpoints_carry_card_images_and_variants(): void
    {
        [$product] = $this->productWithColors();

        $card = collect($this->getJson("/api/products?seller_id={$product->seller_id}")->assertOk()->json('data.data'))
            ->firstWhere('id', $product->id);
        $this->assertCount(5, $card['card_images']);
        $this->assertCount(2, $card['card_swatches']);

        // Raw-row payloads (search / by-ids) get their variants too, so the card knows a choice is needed
        $row = collect($this->postJson('/api/products/by-ids', ['ids' => [$product->id]])->assertOk()->json('products'))->first();
        $this->assertCount(5, $row['card_images']);
        $this->assertCount(2, $row['variants']);
    }

    public function test_promo_flyers_link_the_offer_and_skip_offers_already_in_the_grid(): void
    {
        $seller  = $this->makeUser('seller');
        $product = $this->makeProduct($seller, $this->makeCategory());
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'products/flyer.jpg', 'order' => 0, 'is_primary' => true]);
        $promo = Promotion::create([
            'seller_id' => $seller->id, 'name' => 'Flyer promo ' . Str::random(4), 'type' => 'flash_sale',
            'discount_type' => 'percentage', 'discount_value' => 30, 'status' => 'active', 'priority' => 5,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
        ]);
        $promo->products()->attach($product->id);

        $flyer = collect($this->getJson('/api/promo-flyers?limit=6')->assertOk()->json('data'))->firstWhere('id', $promo->id);
        $this->assertNotNull($flyer);
        $this->assertSame('flash_sale', $flyer['type']);
        $this->assertSame(['type' => 'product', 'href' => "/products/{$product->slug}"], $flyer['link']);
        $this->assertSame($product->id, $flyer['product']['id']);

        // Its only product is already on the page: no flyer repeating it
        $ids = collect($this->getJson("/api/promo-flyers?limit=6&exclude[]={$product->id}")->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($promo->id, $ids);
    }

    public function test_ads_config_exposes_grid_density(): void
    {
        $this->getJson('/api/ads/config')->assertOk()
            ->assertJsonPath('data.grid_ad_every', 8)
            ->assertJsonPath('data.grid_flyer_every', 12);
    }
}
