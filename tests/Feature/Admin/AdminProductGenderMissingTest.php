<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin products list: a "gender missing" flag + filter for Mode & Vêtements products
 * (they are hidden from Femme / Homme browsing and from the chatbot's gendered searches),
 * and the gender can be set from the product editor.
 */
class AdminProductGenderMissingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Product $untagged;
    private Product $tagged;
    private Product $nonGendered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $this->admin = User::query()->forceCreate([
            'name' => 'Admin ' . Str::random(4), 'email' => 'adm_' . Str::random(10) . '@test.local',
            'password' => bcrypt('x-password'), 'role' => 'admin', 'is_active' => true, 'is_approved' => true,
        ]);
        $seller = User::query()->forceCreate([
            'name' => 'Seller ' . Str::random(4), 'email' => 'sel_' . Str::random(10) . '@test.local',
            'password' => bcrypt('x-password'), 'role' => 'seller', 'is_active' => true, 'is_approved' => true,
        ]);

        $s = Str::random(6);
        $mode  = Category::create(['name' => "Mode $s", 'name_ar' => "Mode $s", 'name_fr' => "Mode $s", 'slug' => "mode-$s", 'is_active' => true]);
        $other = Category::create(['name' => "Other $s", 'name_ar' => "Other $s", 'name_fr' => "Other $s", 'slug' => "other-$s", 'is_active' => true]);
        DB::table('categories')->where('id', $mode->id)->update(['is_gendered' => true]);

        $make = fn (Category $c, ?string $gender) => tap(Product::create([
            'seller_id' => $seller->id, 'category_id' => $c->id, 'name' => 'Gender test ' . Str::random(5), 'slug' => 'gt-' . Str::random(10),
            'description' => 'x', 'price' => 50, 'stock' => 5, 'is_approved' => true, 'is_active' => true,
        ]), fn ($p) => DB::table('products')->where('id', $p->id)->update(['gender' => $gender]));

        $this->untagged    = $make($mode, null);
        $this->tagged      = $make($mode, 'women');
        $this->nonGendered = $make($other, null);
    }

    public function test_untagged_mode_products_are_flagged_and_filterable(): void
    {
        Sanctum::actingAs($this->admin);

        $all = collect($this->getJson('/api/admin/products?status=all&per_page=100')->assertOk()->json('data.data'))->keyBy('id');

        $this->assertTrue($all[$this->untagged->id]['gender_missing']);
        $this->assertFalse($all[$this->tagged->id]['gender_missing']);
        $this->assertFalse($all[$this->nonGendered->id]['gender_missing'], 'only gendered categories need a gender');

        $filtered = collect($this->getJson('/api/admin/products?status=all&per_page=100&gender_missing=1')->assertOk()->json('data.data'));
        $this->assertContains($this->untagged->id, $filtered->pluck('id')->all());
        $this->assertNotContains($this->tagged->id, $filtered->pluck('id')->all());
        $this->assertNotContains($this->nonGendered->id, $filtered->pluck('id')->all());
    }

    public function test_the_gender_can_be_set_from_the_gender_endpoint_and_clears_the_flag(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/products/{$this->untagged->id}/gender", ['gender' => 'men'])->assertOk();

        $this->assertSame('men', DB::table('products')->where('id', $this->untagged->id)->value('gender'));
        $filtered = collect($this->getJson('/api/admin/products?status=all&per_page=100&gender_missing=1')->json('data.data'));
        $this->assertNotContains($this->untagged->id, $filtered->pluck('id')->all());
    }
}
