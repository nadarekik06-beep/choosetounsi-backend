<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\ProductImages;
use App\Support\ProductIdentifiers;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seller products: slug and SKU are always made by the server, never taken from the
 * request, and never changed by a seller edit.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/ProductSlugSkuTest.php
 */
class ProductSlugSkuTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        ProductImages::flush();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $this->seller = User::create(['name' => 'Slug Seller', 'email' => 'slug_' . Str::random(8) . '@test.local', 'password' => bcrypt('x-secret-x'), 'role' => 'seller', 'is_active' => true]);
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Slug', 'phone_number' => '20000000', 'business_name' => 'Slug shop',
            'business_category' => 'other', 'wilaya' => 'Tunis', 'city' => 'Tunis', 'status' => 'approved', 'plan' => 'free',
        ]);
        $r = Str::random(5);
        $this->category = Category::create(['name' => "C $r", 'name_ar' => "C $r", 'name_fr' => "C $r", 'slug' => "c-$r", 'is_active' => true]);
    }

    private function create(string $name, array $extra = [])
    {
        Sanctum::actingAs($this->seller);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        return $this->post('/api/seller/products', $extra + [
            'name'              => $name,
            'short_description' => 'Lampe de bureau en bois, lumière chaude',
            'price'             => 25,
            'stock'             => 4,
            'category_id'       => $this->category->id,
            'parcel_size'       => 'S',
            'image_manifest'    => json_encode(['gallery' => [['upload' => 'g1']], 'color_groups' => []]),
            'uploads'           => ['g1' => UploadedFile::fake()->createWithContent('p.png', $png . Str::random(8))],
        ], ['Accept' => 'application/json']);
    }

    private function created(string $name, array $extra = []): Product
    {
        $res = $this->create($name, $extra);
        $res->assertCreated();
        return Product::findOrFail($res->json('data.id'));
    }

    private function assertValidIdentifiers(Product $p): void
    {
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $p->slug);
        $this->assertSame('CT-' . str_pad((string) $p->id, 6, '0', STR_PAD_LEFT), $p->sku);
        $this->assertSame(1, Product::withTrashed()->where('slug', $p->slug)->count());
        $this->assertSame(1, Product::withTrashed()->where('sku', $p->sku)->count());
    }

    public function test_french_name_gets_a_slug_and_sku(): void
    {
        $tag = Str::lower(Str::random(5));
        $p = $this->created("Jebba brodée à la main $tag");
        $this->assertSame("jebba-brodee-a-la-main-$tag", $p->slug);
        $this->assertValidIdentifiers($p);
    }

    public function test_arabic_only_name_gets_a_slug_and_sku(): void
    {
        $p = $this->created('كسوة تقليدية للأطفال');
        $this->assertNotSame('', $p->slug);
        $this->assertValidIdentifiers($p);
    }

    public function test_name_without_letters_falls_back_to_a_generated_slug(): void
    {
        $a = $this->created('★★★ !!!');
        $b = $this->created('★★★ !!!');
        $this->assertStringStartsWith('produit-', $a->slug);
        $this->assertNotSame($a->slug, $b->slug);
        $this->assertValidIdentifiers($a);
        $this->assertValidIdentifiers($b);
    }

    public function test_same_name_as_an_existing_product_gets_a_different_slug_and_sku(): void
    {
        $name = 'Panier en alfa ' . Str::random(4);
        $a = $this->created($name);
        $b = $this->created($name);
        $c = $this->created($name);
        $this->assertSame($a->slug . '-2', $b->slug);
        $this->assertSame($a->slug . '-3', $c->slug);
        $this->assertNotSame($a->sku, $b->sku);
        foreach ([$a, $b, $c] as $p) $this->assertValidIdentifiers($p);
    }

    public function test_arabic_duplicate_names_get_unique_slugs(): void
    {
        $a = $this->created('حذاء رياضي');
        $b = $this->created('حذاء رياضي');
        $this->assertNotSame($a->slug, $b->slug);
        $this->assertValidIdentifiers($a);
        $this->assertValidIdentifiers($b);
    }

    public function test_slug_and_sku_sent_by_the_seller_are_ignored(): void
    {
        // An existing product already uses them: before, the duplicate SKU was a 500 error
        $taken = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'name' => 'Taken',
            'slug' => 'taken-slug-' . Str::random(5), 'sku' => 'TAKEN-' . Str::random(5), 'price' => 10, 'stock' => 1,
        ]);
        $name = 'Coussin berbère ' . Str::random(4);
        $res = $this->create($name, ['slug' => $taken->slug, 'sku' => $taken->sku]);
        $res->assertCreated();
        $this->assertArrayNotHasKey('slug', $res->json('errors') ?? []);
        $p = Product::findOrFail($res->json('data.id'));
        $this->assertSame(Str::slug($name), $p->slug);
        $this->assertValidIdentifiers($p);

        $p2 = $this->created('Coussin ' . Str::random(4), ['slug' => 'my-own-slug', 'sku' => 'MY-SKU']);
        $this->assertNotSame('my-own-slug', $p2->slug);
        $this->assertNotSame('MY-SKU', $p2->sku);
    }

    public function test_editing_the_name_keeps_slug_and_sku(): void
    {
        $p = $this->created('Tapis kilim ' . Str::random(4));
        [$slug, $sku] = [$p->slug, $p->sku];

        $this->postJson("/api/seller/products/{$p->id}", [
            'name' => 'Tapis kilim rouge renommé', 'slug' => 'new-slug-' . Str::random(4), 'sku' => 'NEW-SKU',
        ])->assertOk();

        $p->refresh();
        $this->assertSame('Tapis kilim rouge renommé', $p->name);
        $this->assertSame($slug, $p->slug);
        $this->assertSame($sku, $p->sku);
    }

    public function test_editing_a_legacy_product_without_sku_keeps_it_empty(): void
    {
        $p = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'name' => 'Legacy',
            'slug' => 'legacy-' . Str::random(5), 'price' => 10, 'stock' => 1, 'is_approved' => false, 'is_active' => true,
        ]);
        Sanctum::actingAs($this->seller);
        $this->postJson("/api/seller/products/{$p->id}", ['name' => 'Legacy renamed'])->assertOk();
        $p->refresh();
        $this->assertStringStartsWith('legacy-', $p->slug);
        $this->assertNull($p->sku);
    }

    public function test_sku_skips_a_value_already_used_by_a_variant_or_product(): void
    {
        $id = (int) Product::withTrashed()->max('id') + 1000;
        Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'name' => 'Squatter',
            'slug' => 'squatter-' . Str::random(5), 'sku' => 'CT-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT), 'price' => 10, 'stock' => 1,
        ]);
        $this->assertSame('CT-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) . '-2', ProductIdentifiers::skuFor($id));
    }
}
