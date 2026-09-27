<?php

namespace Tests\Feature\Seller;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChangeSet;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\ProductImages;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Color group images are the only variant images (no per-size images); the seller
 * form saves them through an ordered manifest (add / delete / replace / reorder).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/ProductImagesTest.php
 */
class ProductImagesTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private Category $category;
    private Subcategory $sub;
    private Subcategory $sizesOnly;
    /** @var array<string, int> */
    private array $o = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        ProductImages::flush();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $this->seller = User::create(['name' => 'Img Seller', 'email' => 'img_' . Str::random(8) . '@test.local', 'password' => bcrypt('x-secret-x'), 'role' => 'seller', 'is_active' => true]);
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Img', 'phone_number' => '20000000', 'business_name' => 'Img shop',
            'business_category' => 'other', 'wilaya' => 'Tunis', 'city' => 'Tunis', 'status' => 'approved', 'plan' => 'free',
        ]);

        $r = Str::random(5);
        $this->category  = Category::create(['name' => "C $r", 'name_ar' => "C $r", 'name_fr' => "C $r", 'slug' => "c-$r", 'is_active' => true]);
        $this->sub       = Subcategory::create(['category_id' => $this->category->id, 'name' => "Shoes $r", 'name_ar' => 'x', 'name_fr' => 'x', 'slug' => "shoes-$r", 'is_active' => true]);
        $this->sizesOnly = Subcategory::create(['category_id' => $this->category->id, 'name' => "Rings $r", 'name_ar' => 'x', 'name_fr' => 'x', 'slug' => "rings-$r", 'is_active' => true]);

        $color = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color', 'name_ar' => 'Color', 'name_fr' => 'Couleur', 'type' => 'color']);
        $size  = Attribute::create(['name' => 'Size', 'name_ar' => 'Size', 'name_fr' => 'Pointure', 'slug' => "size-$r", 'type' => 'select']);
        foreach (['Blanc' => '#ffffff', 'Vert' => '#00aa00', 'Noir' => '#000000'] as $v => $hex) {
            $this->o[$v] = AttributeOption::create(['attribute_id' => $color->id, 'value' => "$v $r", 'color_hex' => $hex])->id;
        }
        foreach (['37', '38', '39'] as $v) {
            $this->o[$v] = AttributeOption::create(['attribute_id' => $size->id, 'value' => $v])->id;
        }
        DB::table('subcategory_attributes')->insert([
            ['subcategory_id' => $this->sub->id, 'attribute_id' => $color->id, 'is_variant' => true, 'is_required' => false, 'order' => 1],
            ['subcategory_id' => $this->sub->id, 'attribute_id' => $size->id,  'is_variant' => true, 'is_required' => false, 'order' => 2],
            ['subcategory_id' => $this->sizesOnly->id, 'attribute_id' => $size->id, 'is_variant' => true, 'is_required' => false, 'order' => 1],
        ]);
    }

    private function file(string $name = 'p.png'): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent($name, $png . Str::random(8));
    }

    private function product(Subcategory $sub, array $combos, bool $approved = true): Product
    {
        $name = 'Img Product ' . Str::random(6);
        $p = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'subcategory_id' => $sub->id,
            'name' => $name, 'slug' => Str::slug($name), 'price' => 50, 'stock' => 3 * max(1, count($combos)),
            'is_approved' => $approved, 'is_active' => true,
        ]);
        foreach ($combos as $combo) {
            $v = ProductVariant::create(['product_id' => $p->id, 'stock' => 3, 'is_active' => true]);
            $v->attributeOptions()->sync(array_map(fn($k) => $this->o[$k], $combo));
        }
        return $p;
    }

    private function colorImage(Product $p, array $colors, int $order): ProductImage
    {
        $path = 'products/' . Str::random(12) . '.png';
        Storage::disk('public')->put($path, 'x');
        $first = null;
        foreach ($colors as $c) {
            $row = ProductImage::create(['product_id' => $p->id, 'image_path' => $path, 'color_option_id' => $this->o[$c], 'order' => $order, 'is_primary' => false]);
            $first ??= $row;
        }
        return $first;
    }

    private function key(string ...$colors): string
    {
        $ids = array_map(fn($c) => $this->o[$c], $colors);
        sort($ids);
        return implode('|', $ids);
    }

    private function save(Product $p, array $manifest, array $uploads = [], array $extra = [])
    {
        Sanctum::actingAs($this->seller);
        return $this->post("/api/seller/products/{$p->id}", $extra + [
            'image_manifest' => json_encode($manifest),
            'uploads'        => $uploads,
        ], ['Accept' => 'application/json']);
    }

    private function groupItems(array $manifestGroups): array
    {
        return array_map(fn($color, $items) => ['color_option_ids' => [$this->o[$color]], 'items' => $items],
            array_keys($manifestGroups), array_values($manifestGroups));
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_colors_and_sizes_add_reorder_replace_delete(): void
    {
        // Blanc / 37-39
        $p  = $this->product($this->sub, [['Blanc', '37'], ['Blanc', '38'], ['Blanc', '39']]);
        $a  = $this->colorImage($p, ['Blanc'], 0);
        $b  = $this->colorImage($p, ['Blanc'], 1);
        $c  = $this->colorImage($p, ['Blanc'], 2);
        $oldB = $b->image_path;
        $oldC = $c->image_path;

        // c first, replace b in place, drop… nothing else, add one new
        $this->save($p, ['gallery' => [], 'color_groups' => [[
            'color_option_ids' => [$this->o['Blanc']],
            'items' => [['id' => $c->id], ['upload' => 'u1', 'replaces' => $b->id], ['id' => $a->id], ['upload' => 'u2']],
        ]]], ['u1' => $this->file(), 'u2' => $this->file()])->assertOk();

        $sets = ProductImages::sets($p->fresh());
        $this->assertSame([], $sets['gallery']);
        $group = $sets['color_groups'][0];
        $this->assertSame($this->key('Blanc'), $group['key']);
        $this->assertCount(4, $group['images']);
        $this->assertSame($oldC, $group['images'][0]['path'], 'reordered: first = main image');
        $this->assertSame($a->image_path, $group['images'][2]['path']);
        Storage::disk('public')->assertMissing($oldB);
        $this->assertSame($c->id, ProductImage::where('product_id', $p->id)->where('is_primary', true)->value('id'), 'cover = main image of the first color');

        // Delete one
        $this->save($p, ['gallery' => [], 'color_groups' => [[
            'color_option_ids' => [$this->o['Blanc']],
            'items' => array_map(fn($i) => ['id' => $i['id']], array_slice($group['images'], 1)),
        ]]])->assertOk();
        Storage::disk('public')->assertMissing($oldC);
        $this->assertCount(3, ProductImages::sets($p->fresh())['color_groups'][0]['images']);
    }

    public function test_two_colors_storefront_and_thumbnails(): void
    {
        $p = $this->product($this->sub, [['Vert', '37'], ['Vert', '38'], ['Noir', '37']]);
        $this->save($p, ['gallery' => [], 'color_groups' => $this->groupItems([
            'Vert' => [['upload' => 'v1'], ['upload' => 'v2']],
            'Noir' => [['upload' => 'n1']],
        ])], ['v1' => $this->file(), 'v2' => $this->file(), 'n1' => $this->file()])->assertOk();

        $sets  = collect(ProductImages::sets($p->fresh())['color_groups'])->keyBy('key');
        $vert1 = $sets[$this->key('Vert')]['images'][0]['path'];
        $noir1 = $sets[$this->key('Noir')]['images'][0]['path'];

        // Storefront: sizes of a color share images, colors differ
        $page = $this->getJson('/api/products/' . $p->slug)->assertOk()->json('data');
        $byVariant = collect($page['variants'])->keyBy('id');
        $vs = $p->variants()->get();
        $this->assertSame($byVariant[$vs[0]->id]['image_urls'], $byVariant[$vs[1]->id]['image_urls'], 'Vert 37 = Vert 38');
        $this->assertNotSame($byVariant[$vs[0]->id]['image_urls'], $byVariant[$vs[2]->id]['image_urls']);
        $this->assertCount(2, $byVariant[$vs[0]->id]['image_urls']);

        // Thumbnails (cart / orders / invoices): the color's main image
        $this->assertSame(Storage::url($vert1), ProductImages::thumbnailFor($p->fresh(), $vs[1]));
        $this->assertSame(Storage::url($noir1), ProductImages::thumbnailFor($p->fresh(), $vs[2]));

        $client = User::create(['name' => 'Cl', 'email' => 'cl_' . Str::random(8) . '@test.local', 'password' => bcrypt('x-secret-x'), 'role' => 'client', 'is_active' => true]);
        Cart::create(['user_id' => $client->id, 'product_id' => $p->id, 'variant_id' => $vs[2]->id, 'quantity' => 1]);
        Sanctum::actingAs($client);
        $this->assertStringContainsString(basename($noir1), json_encode($this->getJson('/api/cart')->json()));
    }

    public function test_each_color_needs_an_image(): void
    {
        $p = $this->product($this->sub, [['Vert', '37'], ['Noir', '37']]);
        $this->save($p, ['gallery' => [], 'color_groups' => $this->groupItems(['Vert' => [['upload' => 'v1']]])], ['v1' => $this->file()])
            ->assertStatus(422)->assertJsonValidationErrors('images.color_groups');
        $this->assertSame(0, ProductImage::where('product_id', $p->id)->count(), 'nothing saved');
    }

    public function test_images_of_a_removed_color_are_rejected_and_limits_enforced(): void
    {
        $p = $this->product($this->sub, [['Vert', '37']]);
        $this->save($p, ['gallery' => [], 'color_groups' => $this->groupItems([
            'Vert' => [['upload' => 'a']], 'Noir' => [['upload' => 'b']],
        ])], ['a' => $this->file(), 'b' => $this->file()])->assertStatus(422)->assertJsonValidationErrors('images.color_groups.1');

        $six = [];
        foreach (range(1, 6) as $i) $six["f$i"] = $this->file();
        $this->save($p, ['gallery' => [], 'color_groups' => $this->groupItems([
            'Vert' => array_map(fn($k) => ['upload' => $k], array_keys($six)),
        ])], $six)->assertStatus(422)->assertJsonValidationErrors('images.color_groups.0.items');
    }

    public function test_sizes_only_and_no_variants_use_the_gallery(): void
    {
        foreach ([[['37'], ['38']], []] as $combos) {
            $p = $this->product($combos ? $this->sizesOnly : $this->sub, $combos);
            $this->save($p, ['gallery' => [['upload' => 'g1'], ['upload' => 'g2']], 'color_groups' => []],
                ['g1' => $this->file(), 'g2' => $this->file()])->assertOk();
            $sets = ProductImages::sets($p->fresh());
            $this->assertCount(2, $sets['gallery']);
            $this->assertSame([], $sets['color_groups']);
            $this->assertSame(0, ProductImage::where('product_id', $p->id)->whereNotNull('color_option_id')->count());
        }
    }

    public function test_per_variant_uploads_are_no_longer_accepted(): void
    {
        $p = $this->product($this->sub, [['Blanc', '37']]);
        $v = $p->variants()->first();
        $this->colorImage($p, ['Blanc'], 0);
        Sanctum::actingAs($this->seller);
        $this->post("/api/seller/products/{$p->id}", ["variant_images" => [$v->id => [$this->file()]]], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(0, ProductImage::where('product_id', $p->id)->whereNotNull('variant_id')->count());
    }

    public function test_image_changes_are_logged_once_with_color_names(): void
    {
        $p = $this->product($this->sub, [['Vert', '37']]);
        $a = $this->colorImage($p, ['Vert'], 0);
        $this->save($p, ['gallery' => [], 'color_groups' => $this->groupItems([
            'Vert' => [['upload' => 'n'], ['id' => $a->id]],
        ])], ['n' => $this->file()])->assertOk();

        $set = ProductChangeSet::where('product_id', $p->id)->sole();
        $this->assertTrue($set->is_sensitive);
        $item = $set->items()->where('group', 'images')->sole();
        $this->assertStringContainsString('Vert', $item->label);
        $this->assertStringContainsString('1 added', $item->label);
    }

    public function test_migration_moves_legacy_variant_images_into_color_groups(): void
    {
        $p = $this->product($this->sub, [['Blanc', '37'], ['Blanc', '38']]);
        [$v37, $v38] = $p->variants()->get()->all();
        $existing = $this->colorImage($p, ['Blanc'], 0);
        $legacy = [];
        foreach ([[$v37, 'a'], [$v38, 'b']] as [$v, $n]) {
            $path = "products/legacy-$n-" . Str::random(6) . '.png';
            Storage::disk('public')->put($path, 'x');
            $legacy[] = ProductImage::create(['product_id' => $p->id, 'variant_id' => $v->id, 'image_path' => $path, 'order' => 5, 'is_primary' => false]);
        }
        // A duplicate of the existing color image stored on a variant too
        ProductImage::create(['product_id' => $p->id, 'variant_id' => $v38->id, 'image_path' => $existing->image_path, 'order' => 6, 'is_primary' => false]);

        // Over the limit: 6 legacy images on one color → left untouched
        $full = $this->product($this->sub, [['Noir', '37']]);
        $fv = $full->variants()->first();
        foreach (range(1, 6) as $i) {
            ProductImage::create(['product_id' => $full->id, 'variant_id' => $fv->id, 'image_path' => "products/full-$i.png", 'order' => $i, 'is_primary' => false]);
        }

        if (!class_exists(\MoveVariantImagesToColorGroups::class)) {
            require_once base_path('database/migrations/2026_09_27_100000_move_variant_images_to_color_groups.php');
        }
        ob_start();
        (new \MoveVariantImagesToColorGroups())->up();
        $out = ob_get_clean();

        $this->assertSame(0, ProductImage::where('product_id', $p->id)->whereNotNull('variant_id')->count());
        $group = ProductImages::sets($p->fresh())['color_groups'][0];
        $this->assertSame($this->key('Blanc'), $group['key']);
        $this->assertCount(3, $group['images'], 'existing + 2 legacy, duplicate merged');
        foreach ($legacy as $l) Storage::disk('public')->assertExists($l->image_path);

        $this->assertSame(6, ProductImage::where('product_id', $full->id)->whereNotNull('variant_id')->count(), 'untouched');
        $this->assertStringContainsString((string) $full->id, $out);
    }
}
