<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\ProductImages;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI service stopped (local dev) with indexing on and QUEUE_CONNECTION=sync: the photo
 * fingerprint job runs inside the request, and must not turn a product create or delete into a 500.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/ProductSearchOutageTest.php
 */
class ProductSearchOutageTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        ProductImages::flush();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());
        // phpunit.xml points the AI service at a closed port; turn the index observers on.
        config(['search.indexing' => true, 'queue.default' => 'sync']);

        $this->seller = User::create(['name' => 'Outage Seller', 'email' => 'outage_' . Str::random(8) . '@test.local', 'password' => bcrypt('x-secret-x'), 'role' => 'seller', 'is_active' => true]);
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Outage', 'phone_number' => '20000000', 'business_name' => 'Outage shop',
            'business_category' => 'other', 'wilaya' => 'Tunis', 'city' => 'Tunis', 'status' => 'approved', 'plan' => 'free',
        ]);
        $r = Str::random(5);
        $this->category = Category::create(['name' => "C $r", 'name_ar' => "C $r", 'name_fr' => "C $r", 'slug' => "c-$r", 'is_active' => true]);
    }

    private function file(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent('p.png', $png . Str::random(8));
    }

    public function test_product_is_created_when_the_ai_service_is_down(): void
    {
        Sanctum::actingAs($this->seller);
        $res = $this->post('/api/seller/products', [
            'name'           => 'Outage product',
            'price'          => 25,
            'stock'          => 4,
            'category_id'    => $this->category->id,
            'image_manifest' => json_encode(['gallery' => [['upload' => 'g1']], 'color_groups' => []]),
            'uploads'        => ['g1' => $this->file()],
        ], ['Accept' => 'application/json']);

        $res->assertCreated();
        $id = $res->json('data.id');
        $this->assertSame(1, ProductImage::where('product_id', $id)->count());
    }

    public function test_product_is_deleted_when_the_ai_service_is_down(): void
    {
        $product = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'name' => 'To delete',
            'slug' => 'to-delete-' . Str::random(6), 'price' => 10, 'stock' => 3, 'is_approved' => true, 'is_active' => true,
        ]);
        $path = $this->file()->store('products', 'public');
        ProductImage::create(['product_id' => $product->id, 'image_path' => $path, 'is_primary' => true, 'order' => 0]);

        Sanctum::actingAs($this->seller);
        $this->deleteJson("/api/seller/products/{$product->id}")->assertOk()->assertJson(['success' => true]);

        $this->assertNull(Product::withTrashed()->find($product->id));
        $this->assertSame(0, ProductImage::where('product_id', $product->id)->count());
        $this->assertSame(0, ProductVariant::where('product_id', $product->id)->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_a_product_already_removed_for_its_orders_succeeds_again(): void
    {
        $product = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'name' => 'Sold once',
            'slug' => 'sold-once-' . Str::random(6), 'price' => 10, 'stock' => 3, 'is_approved' => true, 'is_active' => true,
        ]);
        $product->delete();

        Sanctum::actingAs($this->seller);
        $this->deleteJson("/api/seller/products/{$product->id}")->assertOk()->assertJson(['success' => true]);
    }

    public function test_another_sellers_product_is_not_found(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other_' . Str::random(8) . '@test.local', 'password' => bcrypt('x-secret-x'), 'role' => 'seller', 'is_active' => true]);
        $product = Product::create([
            'seller_id' => $other->id, 'category_id' => $this->category->id, 'name' => 'Not mine',
            'slug' => 'not-mine-' . Str::random(6), 'price' => 10, 'stock' => 3, 'is_approved' => true, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->seller);
        $this->deleteJson("/api/seller/products/{$product->id}")->assertNotFound();
        $this->assertNotNull(Product::find($product->id));
    }
}
