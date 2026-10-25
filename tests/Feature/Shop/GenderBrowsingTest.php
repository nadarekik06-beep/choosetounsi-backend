<?php

namespace Tests\Feature\Shop;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\GenderMenuGroup;
use App\Models\GenderMenuItem;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\Gender\GenderResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * Gender browsing for gendered categories (Mode & Vêtements → Femme / Homme).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Shop/GenderBrowsingTest.php
 */
class GenderBrowsingTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    private Category $cat;
    private Subcategory $robes, $tshirts, $costumes, $unlinked;
    private User $seller;
    private int $attrId;
    /** @var array<string,int> option text → id, as stored in this database */
    private array $opt = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTranslator();
        Cache::flush();
        app(GenderResolver::class)->flush();

        $attr = Attribute::firstOrCreate(['slug' => 'gender'], ['name' => 'Gender', 'type' => 'select']);
        $this->attrId = $attr->id;
        foreach (['Men', 'Women', 'Unisex', 'Kids', 'Girls'] as $i => $v) {
            $this->opt[$v] = (int) (DB::table('attribute_options')->where(['attribute_id' => $attr->id, 'value' => $v])->value('id')
                ?? DB::table('attribute_options')->insertGetId(['attribute_id' => $attr->id, 'value' => $v, 'order' => $i]));
        }

        $this->seller = $this->makeUser('seller');
        $this->cat    = $this->makeCategory();
        $this->cat->update(['is_gendered' => true]);

        $sub = fn ($slug) => Subcategory::create(['category_id' => $this->cat->id, 'name' => ucfirst($slug), 'slug' => $slug . '-' . Str::random(4), 'is_active' => true]);
        $this->robes    = $sub('robes');
        $this->tshirts  = $sub('tshirts');
        $this->costumes = $sub('costumes');
        $this->unlinked = $sub('divers');

        $col = fn ($gender) => GenderMenuGroup::create(['category_id' => $this->cat->id, 'gender' => $gender, 'key' => 'clothing', 'name' => 'Clothing', 'name_fr' => 'Vêtements']);
        $w = $col('women');
        $m = $col('men');
        foreach ([[$w, $this->robes], [$w, $this->tshirts], [$m, $this->tshirts], [$m, $this->costumes]] as $i => [$g, $s]) {
            GenderMenuItem::create(['group_id' => $g->id, 'subcategory_id' => $s->id, 'sort_order' => $i]);
        }
    }

    /** A product saved the way the seller form saves it: product row, then the attribute row. */
    private function sellerProduct(Subcategory $sub, ?string $genderOption = null, array $attrs = []): Product
    {
        $p = $this->makeProduct($this->seller, $this->cat, array_merge(['subcategory_id' => $sub->id], $attrs));
        if ($genderOption) {
            // Same call as SellerProductController::saveAttributes (select → json_encode of the option id)
            $p->attributeValues()->updateOrCreate(['attribute_id' => $this->attrId], ['value' => json_encode((array) (string) $this->opt[$genderOption])]);
        }
        return $p->fresh();
    }

    private function ids(string $query = ''): array
    {
        $rows = $this->getJson("/api/products?category_slug={$this->cat->slug}&per_page=60{$query}")->assertOk()->json('data.data');
        return array_column($rows, 'id');
    }

    // ── Mapping ─────────────────────────────────────────────────────────────

    public function test_every_stored_format_maps_by_option_text_not_by_id(): void
    {
        $r = app(GenderResolver::class);
        $w = $this->opt['Women'];

        $this->assertSame('women', $r->fromStoredValue("[\"$w\"]"));
        $this->assertSame('women', $r->fromStoredValue("[$w]"));
        $this->assertSame('women', $r->fromStoredValue((string) $w));
        $this->assertSame('women', $r->fromStoredValue(json_encode([json_encode([(string) $w])]))); // double-encoded
        $this->assertSame('unisex', $r->fromStoredValue('["' . $this->opt['Unisex'] . '"]'));
        $this->assertSame('kids', $r->fromStoredValue('["' . $this->opt['Girls'] . '"]'));
        $this->assertSame('unisex', $r->fromStoredValue(json_encode([(string) $w, (string) $this->opt['Men']])));
        $this->assertSame('women', $r->fromStoredValue('Femme'));
        $this->assertSame('men', $r->fromStoredValue('["Homme"]'));
        $this->assertSame('women', $r->fromStoredValue('نساء'));
        $this->assertSame('unisex', $r->fromStoredValue('Unisexe'));
        $this->assertNull($r->fromStoredValue('[0]'));
        $this->assertNull($r->fromStoredValue(null));
        $this->assertNull($r->fromStoredValue('[]'));
    }

    // ── Browsing rules ──────────────────────────────────────────────────────

    public function test_women_only_in_women_men_only_in_men_unisex_in_both(): void
    {
        $women  = $this->sellerProduct($this->tshirts, 'Women');
        $men    = $this->sellerProduct($this->tshirts, 'Men');
        $unisex = $this->sellerProduct($this->tshirts, 'Unisex');

        $this->assertSame(['women', 'seller'], [$women->gender, $women->gender_source]);

        $w = $this->ids('&gender=women');
        $m = $this->ids('&gender=men');
        $this->assertContains($women->id, $w);   $this->assertNotContains($women->id, $m);
        $this->assertContains($men->id, $m);     $this->assertNotContains($men->id, $w);
        $this->assertContains($unisex->id, $w);  $this->assertContains($unisex->id, $m);
    }

    public function test_no_gender_product_stays_in_the_normal_category_page(): void
    {
        $plain = $this->sellerProduct($this->unlinked);
        $this->assertNull($plain->gender);

        $this->assertContains($plain->id, $this->ids());
        $this->assertNotContains($plain->id, $this->ids('&gender=women'));
        $this->assertNotContains($plain->id, $this->ids('&gender=men'));
    }

    public function test_kids_show_in_neither_femme_nor_homme(): void
    {
        $kid = $this->sellerProduct($this->tshirts, 'Kids');
        $this->assertSame('kids', $kid->gender);
        $this->assertNotContains($kid->id, $this->ids('&gender=women'));
        $this->assertNotContains($kid->id, $this->ids('&gender=men'));
        $this->assertContains($kid->id, $this->ids());
    }

    public function test_inherits_from_subcategory_links_and_seller_value_wins(): void
    {
        $robe    = $this->sellerProduct($this->robes);           // linked to Femme only
        $tshirt  = $this->sellerProduct($this->tshirts);         // linked to both
        $costume = $this->sellerProduct($this->costumes);        // Homme only
        $manRobe = $this->sellerProduct($this->robes, 'Men');    // seller says Homme

        $this->assertSame(['women', 'inherited'], [$robe->gender, $robe->gender_source]);
        $this->assertSame(['unisex', 'inherited'], [$tshirt->gender, $tshirt->gender_source]);
        $this->assertSame(['men', 'inherited'], [$costume->gender, $costume->gender_source]);
        $this->assertSame(['men', 'seller'], [$manRobe->gender, $manRobe->gender_source]);
    }

    public function test_seller_edit_and_subcategory_change_keep_gender_in_sync(): void
    {
        $p = $this->sellerProduct($this->tshirts, 'Women');
        $p->attributeValues()->updateOrCreate(['attribute_id' => $this->attrId], ['value' => json_encode([(string) $this->opt['Men']])]);
        $this->assertSame('men', $p->fresh()->gender);

        $p->attributeValues()->where('attribute_id', $this->attrId)->first()->delete();
        $this->assertSame(['unisex', 'inherited'], [$p->fresh()->gender, $p->fresh()->gender_source]);

        $p->update(['subcategory_id' => $this->costumes->id]);
        $this->assertSame('men', $p->fresh()->gender);
    }

    public function test_gender_combines_with_existing_filters_and_bad_values_are_ignored(): void
    {
        $cheap = $this->sellerProduct($this->tshirts, 'Women', ['price' => 20]);
        $dear  = $this->sellerProduct($this->tshirts, 'Women', ['price' => 200]);
        $robe  = $this->sellerProduct($this->robes, 'Women', ['price' => 20]);

        $this->assertEqualsCanonicalizing([$cheap->id, $robe->id], $this->ids('&gender=women&price_max=50'));
        $this->assertSame([$robe->id], $this->ids("&gender=women&subcategory_slug={$this->robes->slug}"));
        $this->assertEqualsCanonicalizing([$cheap->id, $dear->id, $robe->id], $this->ids('&gender=bogus'));

        $rows = $this->getJson("/api/categories/{$this->cat->slug}/products?gender=men")->assertOk()->json('data.data');
        $this->assertSame([], $rows);
    }

    // ── Menu, bar, admin ────────────────────────────────────────────────────

    public function test_mega_menu_bar_and_admin_changes_show_up_at_once(): void
    {
        $this->sellerProduct($this->robes, 'Women');

        $menu = collect($this->getJson('/api/categories/mega-menu')->assertOk()->json('data'))->firstWhere('slug', $this->cat->slug);
        $this->assertSame([$this->robes->slug, $this->tshirts->slug], array_column($menu['genders']['women'][0]['items'], 'slug'));

        // Bar hides empty subcategories (t-shirts has no product yet) and falls back to null image
        $bar = $this->getJson("/api/categories/{$this->cat->slug}/gender-bar?gender=women")->assertOk()->json('data');
        $this->assertSame([$this->robes->slug], array_column($bar, 'slug'));
        $this->assertSame(1, $bar[0]['count']);

        // Admin ticks Homme for "divers": menu updates immediately, its products inherit
        $plain = $this->sellerProduct($this->unlinked);
        Sanctum::actingAs($this->makeUser('admin'));
        $this->putJson("/api/admin/gender-menu/{$this->cat->id}/subcategories/{$this->unlinked->id}/genders", ['genders' => ['men']])->assertOk();

        $menu = collect($this->getJson('/api/categories/mega-menu')->json('data'))->firstWhere('slug', $this->cat->slug);
        $this->assertContains($this->unlinked->slug, array_column($menu['genders']['men'][0]['items'], 'slug'));
        $this->assertSame(['men', 'inherited'], [$plain->fresh()->gender, $plain->fresh()->gender_source]);
    }

    public function test_admin_override_wins_and_auto_removes_it(): void
    {
        $p = $this->sellerProduct($this->tshirts, 'Women');
        Sanctum::actingAs($this->makeUser('admin'));

        $this->putJson("/api/admin/products/{$p->id}/gender", ['gender' => 'men'])->assertOk()->assertJsonPath('data.gender_source', 'admin');
        $this->assertSame('men', $p->fresh()->gender);
        $this->assertNotContains($p->id, $this->ids('&gender=women'));

        $this->putJson("/api/admin/products/{$p->id}/gender", ['gender' => 'auto'])->assertOk();
        $this->assertSame(['women', 'seller'], [$p->fresh()->gender, $p->fresh()->gender_source]);

        $this->getJson("/api/admin/product-genders?category_id={$this->cat->id}&gender=women")->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_admin_routes_are_admin_only(): void
    {
        Sanctum::actingAs($this->makeUser('client'));
        $this->getJson("/api/admin/gender-menu/{$this->cat->id}")->assertStatus(403);
    }

    public function test_backfill_dry_run_writes_nothing_and_real_run_is_idempotent(): void
    {
        $p = $this->sellerProduct($this->robes);
        DB::table('products')->where('id', $p->id)->update(['gender' => null, 'gender_source' => null]);

        $this->artisan('products:backfill-gender', ['--dry-run' => true])->assertExitCode(0);
        $this->assertNull(DB::table('products')->where('id', $p->id)->value('gender'));

        $this->artisan('products:backfill-gender')->assertExitCode(0);
        $this->assertSame('women', DB::table('products')->where('id', $p->id)->value('gender'));

        $this->artisan('products:backfill-gender')->expectsOutput('Updated 0 product(s).')->assertExitCode(0);
    }
}
