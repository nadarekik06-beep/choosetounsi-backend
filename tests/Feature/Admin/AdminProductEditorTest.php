<?php

namespace Tests\Feature\Admin;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductEditLog;
use App\Models\ProductImage;
use App\Models\ProductModerationLog;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin product editor: GET/POST /api/admin/products/{id}/editor.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Admin/AdminProductEditorTest.php
 */
class AdminProductEditorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $seller;
    private Category $category;
    private Subcategory $sub;
    private Attribute $color;
    private Attribute $size;
    private Attribute $material;
    /** @var array<string, AttributeOption> */
    private array $opt = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Approving triggers the Groq translator after the response — keep tests offline
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $this->admin  = $this->makeUser('admin');
        $this->seller = $this->makeUser('seller');

        $s = Str::random(6);
        $this->category = Category::create(['name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s", 'slug' => "cat-$s", 'is_active' => true]);
        $this->sub      = Subcategory::create(['category_id' => $this->category->id, 'name' => "Sub $s", 'name_ar' => "Sub $s", 'name_fr' => "Sub $s", 'slug' => "sub-$s", 'is_active' => true]);

        // The storefront keys color groups on the 'color' slug, which may already exist
        $this->color    = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color', 'name_ar' => 'Color', 'name_fr' => 'Couleur', 'type' => 'color']);
        $this->size     = Attribute::create(['name' => 'Size', 'name_ar' => 'Size', 'name_fr' => 'Taille', 'slug' => "size-$s", 'type' => 'select']);
        $this->material = Attribute::create(['name' => 'Material', 'name_ar' => 'Material', 'name_fr' => 'Matière', 'slug' => "material-$s", 'type' => 'select', 'is_required' => true]);

        foreach (['red' => '#ff0000', 'blue' => '#0000ff', 'black' => '#000000'] as $v => $hex) {
            $this->opt[$v] = AttributeOption::create(['attribute_id' => $this->color->id, 'value' => ucfirst($v), 'color_hex' => $hex]);
        }
        foreach (['s', 'm'] as $v) {
            $this->opt[$v] = AttributeOption::create(['attribute_id' => $this->size->id, 'value' => strtoupper($v)]);
        }
        $this->opt['cotton'] = AttributeOption::create(['attribute_id' => $this->material->id, 'value' => 'Cotton']);
        $this->opt['wool']   = AttributeOption::create(['attribute_id' => $this->material->id, 'value' => 'Wool']);

        DB::table('subcategory_attributes')->insert([
            ['subcategory_id' => $this->sub->id, 'attribute_id' => $this->color->id,    'is_variant' => true,  'is_required' => false, 'order' => 1],
            ['subcategory_id' => $this->sub->id, 'attribute_id' => $this->size->id,     'is_variant' => true,  'is_required' => false, 'order' => 2],
            ['subcategory_id' => $this->sub->id, 'attribute_id' => $this->material->id, 'is_variant' => false, 'is_required' => true,  'order' => 3],
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name'      => 'Editor Test ' . Str::random(5),
            'email'     => 'editor_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    /** A real 1x1 PNG (GD isn't available on this PHP build, so no ->image()). */
    private function file(string $name = 'photo.png'): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent($name, $png);
    }

    /** A pending seller submission: 3 gallery images, a Red+Blue color group (old single-id storage), 3 variants. */
    private function makePendingProduct(): array
    {
        $name    = 'Editor Product ' . Str::random(6);
        $product = Product::create([
            'seller_id'      => $this->seller->id,
            'category_id'    => $this->category->id,
            'subcategory_id' => $this->sub->id,
            'name'           => $name,
            'slug'           => Str::slug($name),
            'price'          => 20,
            'stock'          => 9,
            'is_approved'    => false,
            'is_active'      => true,
        ]);
        ProductModerationLog::record($product, 'submitted', ['to_status' => 'pending']);
        $product->attributeValues()->create(['attribute_id' => $this->material->id, 'value' => json_encode([$this->opt['cotton']->id])]);

        $img = function (array $attrs) use ($product) {
            $path = 'products/' . Str::random(12) . '.jpg';
            Storage::disk('public')->put($path, 'x');
            return ProductImage::create($attrs + ['product_id' => $product->id, 'image_path' => $path, 'is_primary' => false]);
        };
        $g1 = $img(['order' => 0, 'is_primary' => true]);
        $g2 = $img(['order' => 1]);
        $g3 = $img(['order' => 2]);
        // Seller format: a multi-color group stored under one of its ids only
        $c1 = $img(['order' => 3, 'color_option_id' => $this->opt['blue']->id]);

        $v = [];
        foreach ([['red', 'blue', 's'], ['red', 'blue', 'm'], ['black', 's']] as $i => $combo) {
            $v[$i] = ProductVariant::create(['product_id' => $product->id, 'stock' => 3, 'is_active' => true]);
            $v[$i]->attributeOptions()->sync(array_map(fn($k) => $this->opt[$k]->id, $combo));
        }
        // Legacy per-variant image (Black / S): read as part of the Black color group
        $vImg = $img(['order' => 4, 'variant_id' => $v[2]->id]);

        return compact('product', 'g1', 'g2', 'g3', 'c1', 'v', 'vImg');
    }

    /** Builds a save body from the GET payload, the way the admin panel does. */
    private function documentFrom(array $payload): array
    {
        $p = $payload['product'];
        return [
            'loaded_updated_at' => $p['updated_at'],
            'name'              => $p['name'],
            'slug'              => $p['slug'],
            'sku'               => $p['sku'],
            'description'       => $p['description'],
            'short_description' => $p['short_description'],
            'price'             => $p['price'],
            'stock'             => $p['stock'],
            'category_id'       => $p['category_id'],
            'subcategory_id'    => $p['subcategory_id'],
            'is_active'         => $p['is_active'],
            'is_pack'           => $p['is_pack'],
            'free_delivery'     => $p['free_delivery'],
            'occasions'         => $p['occasions'],
            'pack_quantity'     => $p['pack_quantity'],
            'pack_contents'     => $p['pack_contents'],
            'admin_note'        => $p['admin_note'],
            'attributes'        => (array) $payload['attributes'],
            'variants'          => array_map(fn($v) => [
                'id' => $v['id'], 'key' => (string) $v['id'], 'option_ids' => $v['option_ids'],
                'stock' => $v['stock'], 'price_override' => $v['price_override'], 'sku' => $v['sku'], 'is_active' => $v['is_active'],
            ], $payload['variants']),
            'images' => [
                'gallery'      => array_map(fn($i) => ['id' => $i['id']], $payload['images']['gallery']),
                'color_groups' => array_map(fn($g) => [
                    'color_option_ids' => $g['color_option_ids'],
                    'items'            => array_map(fn($i) => ['id' => $i['id']], $g['images']),
                ], $payload['images']['color_groups']),
            ],
        ];
    }

    private function load(Product $product): array
    {
        return $this->getJson("/api/admin/products/{$product->id}/editor")->assertOk()->json('data');
    }

    private function saveDoc(Product $product, array $doc, array $uploads = [])
    {
        return $this->post("/api/admin/products/{$product->id}/editor", [
            'data'    => json_encode($doc),
            'uploads' => $uploads,
        ], ['Accept' => 'application/json']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_only_admins_can_use_the_editor(): void
    {
        ['product' => $product] = $this->makePendingProduct();

        Sanctum::actingAs($this->seller);
        $this->getJson("/api/admin/products/{$product->id}/editor")->assertStatus(403);
    }

    public function test_payload_groups_images_like_the_storefront(): void
    {
        $d = $this->makePendingProduct();
        Sanctum::actingAs($this->admin);

        $data = $this->load($d['product']);

        $this->assertSame([$d['g1']->id, $d['g2']->id, $d['g3']->id], array_column($data['images']['gallery'], 'id'));
        $this->assertSame($d['g1']->id, $data['images']['cover_id']);

        $groups = collect($data['images']['color_groups']);
        $this->assertCount(2, $groups);
        $ids = [$this->opt['red']->id, $this->opt['blue']->id];
        sort($ids);
        $this->assertSame(implode('|', $ids), $groups[0]['key']);
        $this->assertSame([$d['c1']->id], array_column($groups[0]['images'], 'id'));

        // No per-size images: the old Black / S image shows in the Black color group
        $this->assertSame((string) $this->opt['black']->id, $groups[1]['key']);
        $this->assertSame([$d['vImg']->id], array_column($groups[1]['images'], 'id'));
        $this->assertArrayNotHasKey('variants', $data['images']);
        $this->assertCount(3, $data['variants']);
        $this->assertSame([$this->opt['cotton']->id], $data['attributes'][$this->material->slug]);
    }

    public function test_full_edit_and_approve_flow(): void
    {
        $d = $this->makePendingProduct();
        Sanctum::actingAs($this->admin);
        $doc = $this->documentFrom($this->load($d['product']));

        $doc['name']        = 'Fixed Product Name';
        $doc['slug']        = '';
        $doc['price']       = '24.500';
        $doc['description'] = 'A proper description written by the admin.';
        $doc['admin_note']  = 'Fixed blurry cover and wrong stock.';
        $doc['attributes'][$this->material->slug] = [$this->opt['wool']->id];

        // Gallery: g3 first (new cover), replace g2 in place, drop g1, add one new
        $doc['images']['gallery'] = [
            ['id' => $d['g3']->id],
            ['upload' => 'u1', 'replaces' => $d['g2']->id],
            ['upload' => 'u2'],
        ];
        // Color group: keep the old image and add one
        $doc['images']['color_groups'][0]['items'][] = ['upload' => 'u3'];

        // Variants: edit #0, delete Black / S, add Black / M; one more Black photo
        $doc['variants'][0]['stock']          = 10;
        $doc['variants'][0]['price_override'] = '26';
        $doc['variants'] = array_values(array_filter($doc['variants'], fn($v) => $v['id'] !== $d['v'][2]->id));
        $doc['variants'][] = [
            'id' => null, 'key' => 'new_1', 'option_ids' => [$this->opt['black']->id, $this->opt['m']->id],
            'stock' => 4, 'price_override' => '', 'sku' => 'BLK-M', 'is_active' => true,
        ];
        $doc['images']['color_groups'][1]['items'][] = ['upload' => 'u4'];
        $doc['approve'] = true;

        $oldPaths = ['g1' => $d['g1']->image_path, 'g2' => $d['g2']->image_path];

        $res = $this->saveDoc($d['product'], $doc, [
            'u1' => $this->file('replacement.png'),
            'u2' => $this->file('extra.png'),
            'u3' => $this->file('blue-red.png'),
            'u4' => $this->file('black-m.png'),
        ])->assertOk()->assertJsonPath('message', 'Changes saved and product approved.');

        $product = $d['product']->fresh();
        $this->assertSame('Fixed Product Name', $product->getRawOriginal('name'));
        $this->assertSame('fixed-product-name', $product->slug);
        $this->assertSame('24.500', (string) $product->price);
        $this->assertTrue($product->is_approved);
        $this->assertTrue($product->is_active);
        $this->assertNotNull($product->admin_edited_at);
        $this->assertSame($this->admin->id, $product->admin_edited_by);
        $this->assertSame('Fixed blurry cover and wrong stock.', $product->admin_note);
        $this->assertSame(10 + 3 + 4, $product->stock, 'stock follows the variants');

        // Gallery order + cover
        $gallery = $res->json('data.images.gallery');
        $this->assertCount(3, $gallery);
        $this->assertSame($d['g3']->id, $gallery[0]['id']);
        $this->assertSame($d['g3']->id, ProductImage::where('product_id', $product->id)->where('is_primary', true)->value('id'));
        $this->assertSame(1, ProductImage::where('product_id', $product->id)->where('is_primary', true)->count());

        // Removed / replaced files are gone; the legacy Black image stayed (it's in the manifest)
        foreach ($oldPaths as $path) Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($d['g3']->image_path);
        Storage::disk('public')->assertExists($d['c1']->image_path);
        Storage::disk('public')->assertExists($d['vImg']->image_path);
        $this->assertNull(ProductVariant::find($d['v'][2]->id));

        // Color group now has 2 images, each stored under every color of the group
        $group = $res->json('data.images.color_groups.0');
        $this->assertCount(2, $group['images']);
        foreach ($group['images'] as $img) {
            $path = ProductImage::find($img['id'])->image_path;
            $this->assertEqualsCanonicalizing(
                [$this->opt['red']->id, $this->opt['blue']->id],
                ProductImage::where('image_path', $path)->pluck('color_option_id')->all()
            );
        }

        // Black color group: the legacy image (now a color image) + the new upload; no per-size rows left
        $newVariant = ProductVariant::where('product_id', $product->id)->where('sku', 'BLK-M')->firstOrFail();
        $this->assertSame(0, ProductImage::where('product_id', $product->id)->whereNotNull('variant_id')->count());
        $black = $res->json('data.images.color_groups.1');
        $this->assertSame((string) $this->opt['black']->id, $black['key']);
        $this->assertCount(2, $black['images']);
        $this->assertSame($d['vImg']->id, $black['images'][0]['id']);

        // Attributes
        $this->assertSame(json_encode([$this->opt['wool']->id]), $product->attributeValues()->where('attribute_id', $this->material->id)->value('value'));

        // Audit trail + moderation + seller notification
        $log = ProductEditLog::where('product_id', $product->id)->firstOrFail();
        $this->assertSame($this->admin->id, $log->admin_id);
        $this->assertArrayHasKey('name', $log->changes['fields']);
        $this->assertSame(1, $log->changes['images']['replaced']);
        $this->assertContains('Black / M', $log->changes['variants']['added']);
        $this->assertStringContainsString('Updated', $log->summary);
        $this->assertSame('approved', ProductModerationLog::where('product_id', $product->id)->latest('id')->value('action'));

        $notif = $this->seller->notifications()->latest()->first();
        $this->assertSame('approved', $notif->data['action']);
        $this->assertTrue($notif->data['data']['admin_adjusted']);

        // Storefront: product page resolves the color group and the variant images
        $page = $this->getJson('/api/products/' . $product->slug)->assertOk()->json('data');
        $key  = collect([$this->opt['red']->id, $this->opt['blue']->id])->sort()->implode('|');
        $this->assertCount(2, $page['color_images'][$key]);
        $blackM = collect($page['variants'])->firstWhere('id', $newVariant->id);
        $this->assertCount(2, $blackM['image_urls'], 'Black / M shows the Black color images');
        $this->assertStringEndsWith($product->primaryImage->image_path, $page['primary_image_url']);
    }

    public function test_validation_errors_are_keyed_by_field(): void
    {
        $d = $this->makePendingProduct();
        Sanctum::actingAs($this->admin);
        $doc = $this->documentFrom($this->load($d['product']));

        $doc['price'] = '0';
        $doc['occasions'] = [];
        $doc['attributes'][$this->material->slug] = null;          // required info attribute
        $doc['variants'][1]['option_ids'] = $doc['variants'][0]['option_ids']; // duplicate combo
        $doc['variants'][2]['option_ids'] = [$this->opt['black']->id];        // missing size
        $doc['images']['gallery'][] = ['id' => 999999999];

        $this->saveDoc($d['product'], $doc)->assertStatus(422)->assertJsonValidationErrors(['price', 'occasions']);

        $doc['price'] = '20';
        $doc['occasions'] = ['summer'];
        $this->saveDoc($d['product'], $doc)->assertStatus(422)->assertJsonValidationErrors([
            'attributes.' . $this->material->slug,
            'variants.1.option_ids',
            'variants.2.option_ids',
            'images.gallery.3',
        ]);

        // Nothing was written
        $this->assertSame(0, ProductEditLog::where('product_id', $d['product']->id)->count());
        $this->assertFalse($d['product']->fresh()->is_approved);
    }

    public function test_conflict_when_product_changed_after_loading(): void
    {
        $d = $this->makePendingProduct();
        Sanctum::actingAs($this->admin);
        $doc = $this->documentFrom($this->load($d['product']));
        $doc['name'] = 'Admin version';

        $this->travel(5)->seconds();
        $d['product']->update(['name' => 'Seller changed it']);

        $this->saveDoc($d['product'], $doc)->assertStatus(409)->assertJsonPath('conflict', true);

        $doc['force'] = true;
        $this->saveDoc($d['product'], $doc)->assertOk();
        $this->assertSame('Admin version', $d['product']->fresh()->getRawOriginal('name'));
    }

    public function test_saving_without_changes_logs_nothing(): void
    {
        $d = $this->makePendingProduct();
        Sanctum::actingAs($this->admin);
        $doc = $this->documentFrom($this->load($d['product']));

        $this->saveDoc($d['product'], $doc)->assertOk()->assertJsonPath('message', 'No changes to save.');
        $this->assertSame(0, ProductEditLog::where('product_id', $d['product']->id)->count());
        $this->assertNull($d['product']->fresh()->admin_edited_at);
    }
}
