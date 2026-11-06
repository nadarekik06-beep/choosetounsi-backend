<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ProductImages;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A product card never gets an empty image when the product has one: no cover row
 * → first gallery image → first color image; products:fix-cover-images sets the flag.
 */
class ProductCoverImageTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private int $red;

    protected function setUp(): void
    {
        parent::setUp();
        ProductImages::flush();
        $this->seller = User::create([
            'name' => 'Seller ' . Str::random(5), 'email' => 'seller_' . Str::random(10) . '@test.local',
            'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
        ]);
        $color = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color', 'name_ar' => 'Color', 'name_fr' => 'Couleur', 'type' => 'color']);
        $this->red = AttributeOption::create(['attribute_id' => $color->id, 'value' => 'Rouge' . Str::random(4), 'color_hex' => '#ff0000'])->id;
    }

    private function product(): Product
    {
        $name = 'Sandales ' . Str::random(6);
        return Product::create([
            'seller_id' => $this->seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => 75, 'stock' => 5, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    private function img(Product $p, ?int $color, int $order, bool $primary = false): ProductImage
    {
        return ProductImage::create([
            'product_id' => $p->id, 'image_path' => 'products/' . Str::random(10) . '.jpg',
            'color_option_id' => $color, 'order' => $order, 'is_primary' => $primary,
        ]);
    }

    private function sqlCover(Product $p): ?string
    {
        return DB::table('products as p')->where('p.id', $p->id)
            ->value(DB::raw(ProductImages::coverSql('p')));
    }

    public function test_cover_row_wins(): void
    {
        $p = $this->product();
        $this->img($p, null, 0);
        $cover = $this->img($p, $this->red, 1, true);

        $this->assertStringEndsWith($cover->image_path, Product::find($p->id)->primary_image_url);
        $this->assertSame($cover->image_path, $this->sqlCover($p));
    }

    public function test_only_color_images_fall_back_to_the_first_color_image(): void
    {
        $p = $this->product();
        $second = $this->img($p, $this->red, 1);
        $first  = $this->img($p, $this->red, 0);

        $fresh = Product::find($p->id);
        $this->assertStringEndsWith($first->image_path, $fresh->primary_image_url);
        $this->assertFalse($fresh->relationLoaded('images'), 'the accessor must not add images to API payloads');
        $this->assertSame($first->image_path, $this->sqlCover($p));
        $this->assertStringEndsWith($first->image_path, ProductImages::thumbnailFor($fresh));
        $this->assertNotSame($second->id, ProductImages::coverOf($fresh)->id);
    }

    public function test_gallery_image_beats_color_image_without_cover(): void
    {
        $p = $this->product();
        $this->img($p, $this->red, 0);
        $gallery = $this->img($p, null, 1);

        $this->assertStringEndsWith($gallery->image_path, Product::find($p->id)->primary_image_url);
        $this->assertSame($gallery->image_path, $this->sqlCover($p));
    }

    public function test_command_dry_run_then_apply(): void
    {
        $p = $this->product();
        $first = $this->img($p, $this->red, 0);
        $this->img($p, $this->red, 1);

        $this->artisan('products:fix-cover-images', ['--slug' => $p->slug])->assertExitCode(0);
        $this->assertFalse(ProductImage::where('product_id', $p->id)->where('is_primary', true)->exists(), 'dry run writes nothing');

        $this->artisan('products:fix-cover-images', ['--slug' => $p->slug, '--apply' => true])->assertExitCode(0);
        $this->assertSame([$first->id], ProductImage::where('product_id', $p->id)->where('is_primary', true)->pluck('id')->all());
        $this->assertSame(2, ProductImage::where('product_id', $p->id)->count(), 'no image row added or removed');
    }
}
