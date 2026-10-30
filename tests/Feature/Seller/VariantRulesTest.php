<?php

namespace Tests\Feature\Seller;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\Orders\OrderItemSnapshot;
use App\Services\ProductImages;
use App\Support\Multicolor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seller variant rules: one color per variant, a size when the axis exists, one
 * Multicolore per product (with its description), stock on creation — and legacy
 * products (several colors in one variant, no size) stay editable.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/VariantRulesTest.php
 */
class VariantRulesTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private Category $category;
    private Subcategory $sub;
    /** @var array<string, int> */
    private array $o = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        ProductImages::flush();
        Multicolor::flush();
        $this->withHeaders(['Accept-Language' => 'fr']);   // seller-facing messages are French
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $this->seller = User::create(['name' => 'Var Seller', 'email' => 'var_' . Str::random(8) . '@test.local', 'password' => bcrypt('x-secret-x'), 'role' => 'seller', 'is_active' => true]);
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Var', 'phone_number' => '20000000', 'business_name' => 'Var shop',
            'business_category' => 'other', 'wilaya' => 'Tunis', 'city' => 'Tunis', 'status' => 'approved', 'plan' => 'free',
        ]);

        $r = Str::random(5);
        $this->category = Category::create(['name' => "C $r", 'name_ar' => "C $r", 'name_fr' => "C $r", 'slug' => "c-$r", 'is_active' => true]);
        $this->sub      = Subcategory::create(['category_id' => $this->category->id, 'name' => "Crocs $r", 'name_ar' => 'x', 'name_fr' => 'x', 'slug' => "crocs-$r", 'is_active' => true]);

        $color = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color', 'name_ar' => 'Color', 'name_fr' => 'Couleur', 'type' => 'color']);
        $size  = Attribute::create(['name' => 'Shoe Size', 'name_ar' => 'x', 'name_fr' => 'Pointure', 'slug' => "shoe-size-$r", 'type' => 'multiselect']);
        foreach (['Noir' => '#000000', 'Blanc' => '#ffffff'] as $v => $hex) {
            $this->o[$v] = AttributeOption::create(['attribute_id' => $color->id, 'value' => "$v $r", 'color_hex' => $hex])->id;
        }
        foreach (['38', '39'] as $v) {
            $this->o[$v] = AttributeOption::create(['attribute_id' => $size->id, 'value' => $v])->id;
        }
        DB::table('subcategory_attributes')->insert([
            ['subcategory_id' => $this->sub->id, 'attribute_id' => $color->id, 'is_variant' => true, 'is_required' => false, 'order' => 1],
            ['subcategory_id' => $this->sub->id, 'attribute_id' => $size->id,  'is_variant' => true, 'is_required' => false, 'order' => 2],
        ]);

        // The data migration, twice: it must not duplicate anything
        require_once base_path('database/migrations/2026_10_26_100000_add_multicolor_color_option.php');
        $migration = new \AddMulticolorColorOption();
        $migration->up();
        $migration->up();
        Multicolor::flush();
        $this->o['Multi'] = Multicolor::optionId();
    }

    private function file(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent('p.png', $png . Str::random(8));
    }

    /** @param array<int, array{0: array<string>, 1: int}> $rows [[option keys], stock] */
    private function variants(array $rows): array
    {
        return array_map(fn($r) => [
            'option_ids' => array_map(fn($k) => $this->o[$k], $r[0]),
            'stock'      => $r[1],
            'is_active'  => 1,
        ], $rows);
    }

    /** One image per color in the payload (the form requires it). */
    private function create(array $rows, array $extra = [])
    {
        $colorSets = [];
        foreach ($rows as $r) {
            $c = array_values(array_filter($r[0], fn($k) => !in_array($k, ['38', '39'], true)));
            if ($c) $colorSets[implode('|', $c)] = $c;
        }
        $groups = []; $uploads = [];
        foreach (array_values($colorSets) as $i => $c) {
            $groups[]          = ['color_option_ids' => array_map(fn($k) => $this->o[$k], $c), 'items' => [['upload' => "u$i"]]];
            $uploads["u$i"]    = $this->file();
        }

        Sanctum::actingAs($this->seller);
        return $this->post('/api/seller/products', $extra + [
            'name'           => 'Crocs ' . Str::random(5),
            'short_description' => 'Sabots légers en caoutchouc, confortables',
            'price'          => 60,
            'stock'          => 0,
            'category_id'    => $this->category->id,
            'subcategory_id' => $this->sub->id,
            'parcel_size'    => 'S',
            'variants'       => $this->variants($rows),
            'image_manifest' => json_encode(['gallery' => [], 'color_groups' => $groups]),
            'uploads'        => $uploads,
        ], ['Accept' => 'application/json']);
    }

    private function legacyProduct(): Product
    {
        $name = 'Legacy ' . Str::random(6);
        $p = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'subcategory_id' => $this->sub->id,
            'name' => $name, 'slug' => Str::slug($name), 'price' => 50, 'stock' => 50, 'parcel_size' => 'S',
            'is_approved' => true, 'is_active' => true,
        ]);
        // Noir + Blanc in ONE variant, no size (the old builder allowed it)
        $v = ProductVariant::create(['product_id' => $p->id, 'stock' => 50, 'is_active' => true]);
        $v->attributeOptions()->sync([$this->o['Noir'], $this->o['Blanc']]);
        return $p;
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_migration_is_idempotent_and_keeps_a_plain_hex(): void
    {
        $this->assertNotNull($this->o['Multi']);
        $this->assertSame(1, AttributeOption::where('value', Multicolor::VALUE)->count());
        $this->assertSame(1, Attribute::where('slug', Multicolor::LABEL_ATTRIBUTE)->count());

        $opt = AttributeOption::find($this->o['Multi']);
        $this->assertSame(Multicolor::HEX, $opt->getRawOriginal('color_hex'));
        $this->assertSame(Multicolor::GRADIENT, $opt->color_hex);
        $this->assertTrue($opt->toArray()['is_multicolor']);
        $this->assertFalse(AttributeOption::find($this->o['Noir'])->is_multicolor);
        $this->assertSame('#000000', AttributeOption::find($this->o['Noir'])->color_hex);
    }

    public function test_two_colors_by_two_sizes_is_created(): void
    {
        $res = $this->create([[['Noir', '38'], 2], [['Noir', '39'], 2], [['Blanc', '38'], 0], [['Blanc', '39'], 1]]);
        $res->assertCreated();
        $this->assertSame(4, ProductVariant::where('product_id', $res->json('data.id'))->count());
    }

    public function test_missing_size_is_rejected(): void
    {
        $this->create([[['Noir'], 5]])->assertStatus(422)->assertJsonValidationErrors('variants');
    }

    public function test_several_colors_in_one_new_variant_is_rejected(): void
    {
        $res = $this->create([[['Noir', 'Blanc', '38'], 5]]);
        $res->assertStatus(422);
        $this->assertStringContainsString('Multicolore', implode(' ', $res->json('errors.variants')));
    }

    public function test_zero_total_stock_is_rejected_on_creation(): void
    {
        $res = $this->create([[['Noir', '38'], 0], [['Noir', '39'], 0]]);
        $res->assertStatus(422);
        $this->assertContains("Indiquez le stock d'au moins une variante.", $res->json('errors.variants'));
    }

    public function test_no_variants_in_a_subcategory_with_axes_is_rejected(): void
    {
        Sanctum::actingAs($this->seller);
        $this->post('/api/seller/products', [
            'name' => 'No variants', 'price' => 10, 'stock' => 3, 'category_id' => $this->category->id,
            'subcategory_id' => $this->sub->id, 'parcel_size' => 'S',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('variants');
    }

    public function test_second_multicolor_is_rejected(): void
    {
        $res = $this->create([[['Multi', '38'], 1], [['Multi', '38'], 1]]);
        $res->assertStatus(422);
        $this->assertContains("Vous ne pouvez ajouter qu'une seule couleur « Multicolore » par produit.", $res->json('errors.variants'));
    }

    public function test_multicolor_description_is_saved_and_shown(): void
    {
        $res = $this->create([[['Multi', '38'], 3], [['Noir', '38'], 1]], ['multicolor_label' => 'Noir / Blanc']);
        $res->assertCreated();
        $product = Product::find($res->json('data.id'));
        $this->assertSame('Noir / Blanc', Multicolor::labelFor($product));

        // Seller edit: its own field, not an info attribute
        Sanctum::actingAs($this->seller);
        $show = $this->getJson("/api/seller/products/{$product->id}")->assertOk();
        $this->assertSame('Noir / Blanc', $show->json('data.multicolor_label'));
        $this->assertArrayNotHasKey(Multicolor::LABEL_ATTRIBUTE, (array) $show->json('data.existing_attributes'));

        // Order line snapshot: the description and a flag
        $variant = ProductVariant::where('product_id', $product->id)
            ->whereHas('attributeOptions', fn($q) => $q->where('attribute_options.id', $this->o['Multi']))->first();
        $color = collect(OrderItemSnapshot::attributesOf($variant))->firstWhere('option_id', $this->o['Multi']);
        $this->assertSame('Noir / Blanc', $color['value']);
        $this->assertTrue($color['is_multicolor']);
        $this->assertSame(Multicolor::GRADIENT, $color['color_hex']);

        // Removing the Multicolore variant clears the description
        $this->post("/api/seller/products/{$product->id}", [
            'variants' => $this->variants([[['Noir', '38'], 1]]),
            'image_manifest' => json_encode(['gallery' => [], 'color_groups' => [
                ['color_option_ids' => [$this->o['Noir']], 'items' => [['upload' => 'n']]],
            ]]),
            'uploads' => ['n' => $this->file()],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertNull(Multicolor::labelFor($product->fresh()));
    }

    public function test_description_longer_than_40_is_rejected(): void
    {
        $this->create([[['Multi', '38'], 3]], ['multicolor_label' => str_repeat('x', 41)])
            ->assertStatus(422)->assertJsonValidationErrors('multicolor_label');
    }

    public function test_legacy_multi_color_product_stays_editable(): void
    {
        $p = $this->legacyProduct();
        $v = $p->variants()->first();

        Sanctum::actingAs($this->seller);
        $this->getJson("/api/seller/products/{$p->id}")->assertOk();

        // Same combination, new stock: accepted (no size, two colors)
        $this->post("/api/seller/products/{$p->id}", [
            'variants' => [['id' => $v->id, 'option_ids' => [$this->o['Noir'], $this->o['Blanc']], 'stock' => 0, 'is_active' => 1]],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(0, (int) $v->fresh()->stock);

        // Adding a size to the legacy color set: accepted too
        $this->post("/api/seller/products/{$p->id}", [
            'variants' => [['option_ids' => [$this->o['Noir'], $this->o['Blanc'], $this->o['38']], 'stock' => 4, 'is_active' => 1]],
        ], ['Accept' => 'application/json'])->assertOk();

        // A NEW combination still follows the rules (size required)
        $this->post("/api/seller/products/{$p->id}", [
            'variants' => $this->variants([[['Noir', 'Blanc', '38'], 4], [['Blanc'], 2]]),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('variants');
    }
}
