<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\PriceHistory;
use App\Support\Occasions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Season / Occasion (product_occasions) and Multi-pack fields: seller validation,
 * category gating, storefront filters, by-ids payload, no fallback on filters.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/ProductOccasionsPackTest.php
 */
class ProductOccasionsPackTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private Category $fashion;
    private Category $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        PriceHistory::flush();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $this->seller = User::create([
            'name' => 'Occ Seller ' . Str::random(5), 'email' => 'occ_' . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => 'seller', 'is_active' => true,
        ]);
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Occ Test', 'phone_number' => '20000000',
            'business_name' => 'Occ Shop ' . Str::random(4), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => 'free',
        ]);
        $this->fashion = Category::firstOrCreate(['slug' => 'fashion-clothing'],
            ['name' => 'Fashion', 'name_fr' => 'Mode', 'name_ar' => 'Mode', 'is_active' => true]);
        $s = Str::random(6);
        $this->other = Category::create(['name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s", 'slug' => "cat-$s", 'is_active' => true]);
    }

    private function product(Category $cat, array $attrs = [], ?array $occasions = null): Product
    {
        $name = 'Occ Product ' . Str::random(8);
        $p = Product::create($attrs + [
            'seller_id' => $this->seller->id, 'category_id' => $cat->id, 'name' => $name,
            'slug' => Str::slug($name), 'price' => 30, 'stock' => 5, 'is_approved' => true, 'is_active' => true,
        ]);
        $path = 'products/' . Str::random(10) . '.jpg';
        Storage::disk('public')->put($path, 'x');
        ProductImage::create(['product_id' => $p->id, 'image_path' => $path, 'order' => 0, 'is_primary' => true]);
        if ($occasions) $p->syncOccasions($occasions);
        return $p;
    }

    private function edit(Product $p, array $data)
    {
        Sanctum::actingAs($this->seller);
        return $this->postJson("/api/seller/products/{$p->id}", $data);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_new_product_defaults_to_all_season(): void
    {
        $this->assertSame([Occasions::DEFAULT], $this->product($this->fashion)->fresh()->occasions);
    }

    public function test_seller_saves_occasions_for_relevant_category(): void
    {
        $p = $this->product($this->fashion);
        $this->edit($p, ['occasions' => ['winter', 'all_season', 'aid']])->assertOk()
            ->assertJsonPath('data.occasions', ['winter', 'aid']);   // all_season dropped when specific ones are picked
        $this->assertSame(['winter', 'aid'], $p->fresh()->occasions);
    }

    public function test_unknown_occasion_is_a_422_not_silently_dropped(): void
    {
        $p = $this->product($this->fashion, [], ['summer']);
        $this->edit($p, ['occasions' => ['summer', 'spring']])->assertStatus(422)->assertJsonValidationErrors(['occasions.1']);
        $this->assertSame(['summer'], $p->fresh()->occasions);
    }

    public function test_irrelevant_category_is_always_all_season(): void
    {
        $p = $this->product($this->other);
        $this->edit($p, ['occasions' => ['summer']])->assertOk();
        $this->assertSame([Occasions::DEFAULT], $p->fresh()->occasions);

        $q = $this->product($this->fashion, [], ['winter']);
        $this->edit($q, ['category_id' => $this->other->id])->assertOk();
        $this->assertSame([Occasions::DEFAULT], $q->fresh()->occasions, 'moving to a non-occasion category resets');
    }

    public function test_pack_requires_quantity_of_at_least_two(): void
    {
        $p = $this->product($this->fashion);
        $this->edit($p, ['is_pack' => '1'])->assertStatus(422)->assertJsonValidationErrors(['pack_quantity']);
        $this->edit($p, ['is_pack' => '1', 'pack_quantity' => '1'])->assertStatus(422)->assertJsonValidationErrors(['pack_quantity']);
        $this->edit($p, ['is_pack' => '1', 'pack_quantity' => '3', 'pack_contents' => '3 t-shirts'])->assertOk()
            ->assertJsonPath('data.pack_quantity', 3);

        $this->edit($p, ['is_pack' => '0'])->assertOk();
        $p->refresh();
        $this->assertFalse($p->is_pack);
        $this->assertNull($p->pack_quantity, 'pack fields cleared when no longer a pack');
        $this->assertNull($p->pack_contents);
    }

    public function test_legacy_pack_without_quantity_must_set_it_on_next_edit(): void
    {
        $p = $this->product($this->fashion, ['is_pack' => true]);
        $this->edit($p, ['stock' => 9])->assertStatus(422)->assertJsonValidationErrors(['pack_quantity']);
        $this->edit($p, ['stock' => 9, 'pack_quantity' => 2])->assertOk();
        $this->edit($p, ['stock' => 8])->assertOk();   // stored quantity satisfies later edits
    }

    public function test_store_returns_422_for_invalid_fields(): void
    {
        Sanctum::actingAs($this->seller);
        $this->postJson('/api/seller/products', [
            'name' => 'X', 'price' => 10, 'stock' => 1, 'category_id' => $this->fashion->id,
            'is_pack' => '1', 'occasions' => ['nope'],
        ])->assertStatus(422)->assertJsonValidationErrors(['pack_quantity', 'occasions.0']);
    }

    public function test_storefront_filters_and_payloads(): void
    {
        $winterPack = $this->product($this->fashion, ['is_pack' => true, 'pack_quantity' => 4], ['winter']);
        $summer     = $this->product($this->fashion, [], ['summer']);
        $plain      = $this->product($this->fashion);
        $sellerId   = $this->seller->id;

        $ids = fn($res) => collect($res->json('data.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$winterPack->id], $ids($this->getJson("/api/products?seller_id=$sellerId&is_pack=1")));
        $this->assertSame([$summer->id], $ids($this->getJson("/api/products?seller_id=$sellerId&occasions[]=summer")));
        $this->assertSame(collect([$winterPack->id, $summer->id])->sort()->values()->all(),
            $ids($this->getJson("/api/products?seller_id=$sellerId&occasions=summer,winter")));

        $row = collect($this->getJson("/api/products?seller_id=$sellerId&is_pack=1")->json('data.data'))->first();
        $this->assertSame(['winter'], $row['occasions']);
        $this->assertSame(4, $row['pack_quantity']);

        $byIds = $this->postJson('/api/products/by-ids', ['ids' => [$winterPack->id, $plain->id]])->json('products');
        $this->assertSame([true, 4, ['winter']], [$byIds[0]['is_pack'], $byIds[0]['pack_quantity'], $byIds[0]['occasions']]);
        $this->assertSame([false, null, ['all_season']], [$byIds[1]['is_pack'], $byIds[1]['pack_quantity'], $byIds[1]['occasions']]);

        $this->getJson("/api/products/{$winterPack->slug}")->assertJsonPath('data.pack_quantity', 4)
            ->assertJsonPath('data.occasions', ['winter']);
    }

    public function test_logged_in_filtered_listing_never_falls_back_to_other_products(): void
    {
        $this->product($this->other);   // the category has products, but no pack
        Sanctum::actingAs($this->seller);
        $res = $this->getJson("/api/products?category_slug={$this->other->slug}&is_pack=1")->assertOk();
        $this->assertSame([], $res->json('data.data'));
        $this->assertSame(0, $res->json('data.total'));
    }

    public function test_occasions_endpoint_exposes_values_and_categories(): void
    {
        $this->getJson('/api/product-occasions')->assertOk()
            ->assertJsonPath('data.values', Occasions::keys())
            ->assertJsonPath('data.category_slugs', Occasions::CATEGORY_SLUGS);
    }
}
