<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductEditLog;
use App\Models\ProductImage;
use App\Models\ProductModerationLog;
use App\Models\SellerApplication;
use App\Models\Subcategory;
use App\Models\User;
use App\Notifications\ProductAddedByAdminNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Listing quality rules (App\Support\ProductListingRules) on the seller form,
 * admin "Add product" on behalf of a seller, quality warnings and "Split".
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/ProductListingRulesTest.php
 */
class ProductListingRulesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $seller;
    private Category $category;      // has a subcategory
    private Subcategory $sub;
    private Category $flatCategory;  // no subcategory

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Notification::fake();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $this->admin  = $this->makeUser('admin');
        $this->seller = $this->makeUser('seller', 'Perles de Kik');

        $s = Str::random(6);
        $this->category     = Category::create(['name' => "Bags $s", 'name_ar' => "Bags $s", 'name_fr' => "Sacs $s", 'slug' => "bags-$s", 'is_active' => true]);
        $this->sub          = Subcategory::create(['category_id' => $this->category->id, 'name' => "Handbags $s", 'name_ar' => "H $s", 'name_fr' => "Sacs à main $s", 'slug' => "handbags-$s", 'is_active' => true]);
        $this->flatCategory = Category::create(['name' => "Flat $s", 'name_ar' => "Flat $s", 'name_fr' => "Flat $s", 'slug' => "flat-$s", 'is_active' => true]);
    }

    private function makeUser(string $role, ?string $shop = null): User
    {
        $u = User::create([
            'name' => 'Rules ' . Str::random(5), 'email' => 'rules_' . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true, 'is_approved' => true,
        ]);
        if ($shop) {
            SellerApplication::create([
                'user_id' => $u->id, 'full_name' => $u->name, 'phone_number' => '22123456', 'business_name' => $shop,
                'business_category' => 'other', 'wilaya' => 'Tunis', 'city' => 'Tunis', 'status' => 'approved', 'plan' => 'free',
            ]);
        }
        return $u;
    }

    private function file(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent('p.png', $png . Str::random(8));
    }

    /** A valid seller "Add product" request; $override replaces fields (null removes one). */
    private function sellerCreate(array $override = [])
    {
        $body = array_merge([
            'name'              => 'Sac en perles nacrées',
            'short_description' => 'Sac à main en perles fait main, anse chaîne dorée',
            'price'             => 45,
            'stock'             => 3,
            'category_id'       => $this->category->id,
            'subcategory_id'    => $this->sub->id,
            'parcel_size'       => 'S',
            'image_manifest'    => json_encode(['gallery' => [['upload' => 'g1']], 'color_groups' => []]),
            'uploads'           => ['g1' => $this->file()],
        ], $override);
        $body = array_filter($body, fn($v) => $v !== null);

        Sanctum::actingAs($this->seller);
        return $this->post('/api/seller/products', $body, ['Accept' => 'application/json']);
    }

    /** Product #3 as sellers list it today: no subcategory, stock 0, 7 photos, "Meilleure prix". */
    private function legacyProduct(): Product
    {
        $p = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'subcategory_id' => null,
            'name' => 'Sac en perle', 'slug' => 'sac-en-perle-' . Str::random(6), 'short_description' => 'Meilleure prix',
            'price' => 45, 'stock' => 0, 'is_approved' => false, 'is_active' => true, 'parcel_size' => 'S',
        ]);
        ProductModerationLog::record($p, 'submitted', ['to_status' => 'pending']);
        for ($i = 0; $i < 7; $i++) {
            $path = 'products/' . Str::random(12) . '.jpg';
            Storage::disk('public')->put($path, 'x');
            ProductImage::create(['product_id' => $p->id, 'image_path' => $path, 'order' => $i, 'is_primary' => $i === 0]);
        }
        return $p;
    }

    /** Admin editor document for a new product (same shape as the admin panel sends). */
    private function adminDoc(array $override = []): array
    {
        return array_merge([
            'loaded_updated_at' => null,
            'name' => 'Sac en perles bleu', 'slug' => null, 'sku' => null, 'description' => null,
            'short_description' => 'Sac à main en perles bleues, fermoir doré',
            'price' => '55', 'stock' => 4, 'category_id' => $this->category->id, 'subcategory_id' => $this->sub->id,
            'is_active' => true, 'is_pack' => false, 'pack_quantity' => null, 'pack_contents' => null,
            'free_delivery' => false, 'parcel_size' => 'S', 'occasions' => ['all_season'], 'admin_note' => null,
            'attributes' => [], 'variants' => [],
            'images' => ['gallery' => [['upload' => 'a1']], 'color_groups' => []],
        ], $override);
    }

    private function adminCreate(array $doc, bool $publish, ?User $as = null, ?int $sellerId = null)
    {
        Sanctum::actingAs($as ?? $this->admin);
        return $this->post('/api/admin/products', [
            'seller_id' => $sellerId ?? $this->seller->id,
            'publish'   => $publish ? '1' : '0',
            'data'      => json_encode($doc),
            'uploads'   => ['a1' => $this->file()],
        ], ['Accept' => 'application/json']);
    }

    // ── Seller "Add product" ─────────────────────────────────────────────────

    public function test_valid_seller_product_is_created(): void
    {
        $this->sellerCreate()->assertCreated();
    }

    public function test_subcategory_is_required_when_the_category_has_some(): void
    {
        $this->sellerCreate(['subcategory_id' => null])->assertStatus(422)->assertJsonValidationErrors('subcategory_id');
        // A category without subcategories doesn't need one
        $this->sellerCreate(['subcategory_id' => null, 'category_id' => $this->flatCategory->id])->assertCreated();
    }

    public function test_new_product_needs_stock_of_at_least_one(): void
    {
        $this->sellerCreate(['stock' => 0])->assertStatus(422)->assertJsonValidationErrors('stock');
    }

    public function test_short_description_rules(): void
    {
        $this->sellerCreate(['short_description' => 'Meilleure prix'])->assertStatus(422)->assertJsonValidationErrors('short_description');
        $this->sellerCreate(['short_description' => 'Promo promo meilleur prix, bon prix !!'])->assertStatus(422)->assertJsonValidationErrors('short_description');
        $this->sellerCreate(['short_description' => 'Sac en perles, appelez le 22 123 456'])->assertStatus(422)->assertJsonValidationErrors('short_description');
        $this->sellerCreate(['short_description' => null])->assertStatus(422)->assertJsonValidationErrors('short_description');
    }

    public function test_new_product_needs_a_photo(): void
    {
        $this->sellerCreate([
            'image_manifest' => json_encode(['gallery' => [], 'color_groups' => []]), 'uploads' => null,
        ])->assertStatus(422)->assertJsonValidationErrors('images');
    }

    public function test_legacy_product_stays_editable(): void
    {
        $p = $this->legacyProduct();
        Sanctum::actingAs($this->seller);

        // Unrelated edit of product #3's shape: saves, stock 0 allowed (sold out)
        $this->post("/api/seller/products/{$p->id}", [
            'name' => 'Sac en perle blanche', 'short_description' => 'Meilleure prix', 'stock' => 0,
            'category_id' => $this->category->id, 'parcel_size' => 'S',
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('Sac en perle blanche', $p->fresh()->getRawOriginal('name'));
        $this->assertSame(0, (int) $p->fresh()->stock);

        // Changing the category without a subcategory is checked
        $other = Category::create(['name' => 'Other ' . Str::random(4), 'name_ar' => 'x', 'name_fr' => 'x', 'slug' => 'oth-' . Str::random(6), 'is_active' => true]);
        Subcategory::create(['category_id' => $other->id, 'name' => 'S', 'name_ar' => 'S', 'name_fr' => 'S', 'slug' => 's-' . Str::random(6), 'is_active' => true]);
        $this->post("/api/seller/products/{$p->id}", ['category_id' => $other->id], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('subcategory_id');

        // A changed short description must be a real one
        $this->post("/api/seller/products/{$p->id}", ['short_description' => 'Promo'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('short_description');
    }

    public function test_variant_stock_must_be_a_whole_number(): void
    {
        $p = $this->legacyProduct();
        Sanctum::actingAs($this->seller);
        $this->post("/api/seller/products/{$p->id}", ['variants' => [['option_ids' => [1], 'stock' => -2]]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('variants.0.stock');
    }

    // ── Admin "Add product" ──────────────────────────────────────────────────

    public function test_admin_creates_a_published_product_for_a_seller(): void
    {
        $res = $this->adminCreate($this->adminDoc(), true)->assertCreated();
        $p   = Product::findOrFail($res->json('data.id'));

        $this->assertSame($this->seller->id, (int) $p->seller_id);
        $this->assertSame($this->admin->id, (int) $p->created_by_admin_id);
        $this->assertTrue($p->is_approved);
        $this->assertSame('sac-en-perles-bleu', $p->slug);
        $this->assertSame(1, $p->images()->count());
        $this->assertSame('approved', $p->moderationStatus());
        $this->assertTrue(ProductEditLog::where('product_id', $p->id)->where('summary', 'like', 'Created by admin%')->exists());
        $this->assertEqualsCanonicalizing(['created_by_admin', 'approved'], ProductModerationLog::where('product_id', $p->id)->pluck('action')->all());

        Notification::assertSentTo($this->seller, ProductAddedByAdminNotification::class, function ($n, $channels) use ($p) {
            $data = $n->toDatabase($this->seller);
            return in_array('mail', $channels, true) && $data['product_id'] === $p->id && str_contains($data['body'], 'Sac en perles bleu');
        });

        // Shows normally in the seller's dashboard, and the seller can edit it
        Sanctum::actingAs($this->seller);
        $this->getJson('/api/seller/products')->assertOk()->assertJsonFragment(['id' => $p->id]);
        $this->post("/api/seller/products/{$p->id}", ['name' => 'Sac en perles bleu nuit'], ['Accept' => 'application/json'])->assertOk();

        // Admin list shows who added it
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/products?status=all&seller_id=' . $this->seller->id)->assertOk()
            ->assertJsonFragment(['id' => $p->id, 'created_by_admin_id' => $this->admin->id]);
    }

    public function test_admin_can_save_a_draft(): void
    {
        $res = $this->adminCreate($this->adminDoc(), false)->assertCreated();
        $p   = Product::findOrFail($res->json('data.id'));
        $this->assertFalse($p->is_approved);
        $this->assertSame('pending', $p->moderationStatus());
    }

    public function test_admin_creation_runs_the_same_rules(): void
    {
        $this->adminCreate($this->adminDoc(['subcategory_id' => null]), true)->assertStatus(422)->assertJsonValidationErrors('subcategory_id');
        $this->adminCreate($this->adminDoc(['stock' => 0]), true)->assertStatus(422)->assertJsonValidationErrors('stock');
        $this->adminCreate($this->adminDoc(['short_description' => 'Meilleure prix']), true)->assertStatus(422)->assertJsonValidationErrors('short_description');
    }

    public function test_only_admins_can_create_and_only_for_approved_sellers(): void
    {
        $this->adminCreate($this->adminDoc(), true, $this->seller)->assertStatus(403);

        $pending = $this->makeUser('seller', 'Pending shop');
        $pending->forceFill(['is_approved' => false])->save();
        $this->adminCreate($this->adminDoc(), true, null, $pending->id)->assertStatus(422)->assertJsonValidationErrors('seller_id');
    }

    public function test_seller_picker_searches_approved_sellers(): void
    {
        Sanctum::actingAs($this->admin);
        $rows = $this->getJson('/api/admin/products/seller-options?search=Perles de Kik')->assertOk()->json('data');
        $this->assertSame([$this->seller->id], array_column($rows, 'id'));
        $this->assertArrayHasKey('product_limit', $rows[0]);
        $this->assertArrayHasKey('plan', $rows[0]);

        $this->getJson('/api/admin/products/blank-editor?seller_id=' . $this->seller->id)->assertOk()
            ->assertJsonPath('data.product.seller.shop_name', 'Perles de Kik');
    }

    // ── Review helpers ───────────────────────────────────────────────────────

    public function test_quality_warnings_for_product_three(): void
    {
        $p = $this->legacyProduct();
        Sanctum::actingAs($this->admin);

        $codes = array_column($this->getJson("/api/admin/products/{$p->id}/review")->assertOk()->json('data.quality_warnings'), 'code');
        $this->assertEqualsCanonicalizing(['missing_subcategory', 'zero_stock', 'many_photos_no_variants', 'short_description_min'], $codes);

        // The admin editor shows them too, and still saves the product as it is
        $payload = $this->getJson("/api/admin/products/{$p->id}/editor")->assertOk()->json('data');
        $this->assertCount(4, $payload['quality_warnings']);
    }

    public function test_split_into_separate_products(): void
    {
        $p   = $this->legacyProduct();
        $ids = $p->images()->orderBy('order')->pluck('id')->all();
        Sanctum::actingAs($this->admin);

        $res = $this->postJson("/api/admin/products/{$p->id}/split", [
            'groups' => [
                ['name' => 'Sac en perles blanches', 'stock' => 2, 'image_ids' => [$ids[0], $ids[1]]],
                ['name' => 'Sac en perles roses', 'stock' => 1, 'price' => 50, 'image_ids' => [$ids[2]]],
            ],
            'move_images' => true,
            'publish'     => false,
        ])->assertCreated();

        $new = Product::whereIn('id', array_column($res->json('data'), 'id'))->orderBy('id')->get();
        $this->assertCount(2, $new);
        $this->assertSame([2, 1], $new->pluck('images')->map->count()->all());
        $this->assertSame($this->seller->id, (int) $new[0]->seller_id);
        $this->assertSame($this->category->id, (int) $new[0]->category_id);
        $this->assertSame('50.000', (string) $new[1]->price);
        $this->assertSame($this->admin->id, (int) $new[0]->created_by_admin_id);
        $this->assertSame(4, $p->images()->count());             // 7 - 3 moved
        $this->assertSame(1, $p->images()->where('is_primary', true)->count());
        $this->assertTrue(ProductEditLog::where('product_id', $p->id)->where('summary', 'like', 'Split into%')->exists());
        Notification::assertSentToTimes($this->seller, ProductAddedByAdminNotification::class, 2);

        // Files are kept: the new products use them
        foreach ($new as $np) foreach ($np->images as $img) Storage::disk('public')->assertExists($img->image_path);

        // Moving every photo away is refused
        $this->postJson("/api/admin/products/{$p->id}/split", [
            'groups' => [['name' => 'Everything', 'stock' => 1, 'image_ids' => $p->images()->pluck('id')->all()]], 'move_images' => true,
        ])->assertStatus(422);
    }
}
