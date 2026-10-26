<?php

namespace Tests\Feature\Shop;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\GenderMenuGroup;
use App\Models\Subcategory;
use App\Services\Catalog\CategoryNameMatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * php artisan categories:import — zero duplicates, additive, idempotent.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Shop/CategoryImportTest.php
 */
class CategoryImportTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    private string $r;          // random token: fixture names never collide with real rows
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTranslator();
        Cache::flush();
        $this->r = strtolower(Str::random(6));
        $this->dir = sys_get_temp_dir() . '/catimport-' . $this->r;
        @mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** rows: [category_slug, cat_fr, cat_en, cat_ar, group_fr, order, sub_slug, sub_fr, sub_en, sub_ar, genders] */
    private function csv(array $rows): string
    {
        $path = "{$this->dir}/cats.csv";
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['category_slug', 'category_fr', 'category_en', 'category_ar', 'menu_group_fr', 'sort_order',
            'subcategory_slug', 'subcategory_fr', 'subcategory_en', 'subcategory_ar', 'genders']);
        foreach ($rows as $row) fputcsv($fh, $row);
        fclose($fh);
        return $path;
    }

    private function decisions(array $d): string
    {
        $path = "{$this->dir}/decisions.php";
        file_put_contents($path, '<?php return ' . var_export($d, true) . ';');
        return $path;
    }

    private function import(string $csv, array $decisions = [], bool $dry = false): int
    {
        $args = ['--file' => $csv, '--decisions' => $this->decisions($decisions)];
        if ($dry) $args['--dry-run'] = true;
        return $this->artisan('categories:import', $args)->run();
    }

    private function cat(string $slug, string $fr, bool $gendered = false): Category
    {
        $c = Category::create(['name' => ucfirst($slug), 'name_fr' => $fr, 'name_ar' => '', 'slug' => $slug, 'is_active' => true]);
        $c->update(['is_gendered' => $gendered]);
        return $c;
    }

    private function sub(Category $c, string $slug, string $en, string $fr, ?string $ar = null): Subcategory
    {
        return Subcategory::create(['category_id' => $c->id, 'name' => $en, 'name_fr' => $fr, 'name_ar' => $ar, 'slug' => $slug, 'is_active' => true]);
    }

    // ── Name matching ───────────────────────────────────────────────────────

    public function test_names_compare_without_case_accents_plurals_and_connectors(): void
    {
        $m = app(CategoryNameMatcher::class);
        $this->assertTrue($m->same(['T-shirt'], ['T-shirts']));
        $this->assertTrue($m->same(['Sweat-shirts'], ['Sweatshirts']));
        $this->assertTrue($m->same(['Robes'], ['robe']));
        $this->assertTrue($m->same(['Dresses'], ['Dress']));
        $this->assertTrue($m->same(['Épicerie'], ['epicerie']));
        $this->assertTrue($m->same(['Tops & Blouses'], ['Blouses et tops']));
        $this->assertTrue($m->same(['Bijoux'], ['Bijou']));
        $this->assertTrue($m->same(['الموضة والملابس'], ['الموضه و الملابس']));
        $this->assertFalse($m->same(['Café & Thé'], ['Café']));
        $this->assertFalse($m->same(['Talons'], ['Talons hauts']));
        $this->assertTrue($m->related(['Talons'], ['Talons hauts']));
        $this->assertTrue($m->related(['Sweat-shirts & Hoodies'], ['Sweat-shirts']));
    }

    // ── Import ──────────────────────────────────────────────────────────────

    public function test_matches_by_slug_and_name_never_renames_fills_missing_translations_and_is_idempotent(): void
    {
        $r = $this->r;
        $cat = $this->cat("zz-$r-maison", "Maizon$r & Déco");
        $byName = $this->sub($cat, "zz-$r-rug", "Rugz$r", "Tapiz$r");            // matched by FR name (plural in CSV)
        $bySlug = $this->sub($cat, "zz-$r-curtains", "Old EN $r", "Ancien FR $r"); // matched by slug, names differ

        $csv = $this->csv([
            // different category slug, same FR name → matched to the existing category
            ["zz-$r-home-decor", "Maizon$r et déco", "Home $r", "منزل $r", "Décoration $r", 1, "zz-$r-rugs", "Tapizs$r", "Rugzs$r", "سجاد $r", ''],
            ["zz-$r-home-decor", "Maizon$r et déco", "Home $r", "منزل $r", "Décoration $r", 2, "zz-$r-curtains", "Rideaux $r", "Curtains $r", "ستائر $r", ''],
            ["zz-$r-home-decor", "Maizon$r et déco", "Home $r", "منزل $r", "Décoration $r", 3, "zz-$r-mirrors", "Mirwar$r", "Mirwor$r", "مرايا $r", ''],
        ]);
        $columns = require database_path('seeders/data/category_import_columns.php');
        // Column names must be known: reuse an existing translated one for the fixture
        $csv = str_replace("Décoration $r", 'Décoration', file_get_contents($csv));
        file_put_contents("{$this->dir}/cats.csv", $csv);
        $this->assertArrayHasKey('Décoration', $columns);

        $before = [Category::count(), Subcategory::count()];
        $this->assertSame(0, $this->import("{$this->dir}/cats.csv", [], true));
        $this->assertSame($before, [Category::count(), Subcategory::count()], 'dry-run writes nothing');

        $this->assertSame(0, $this->import("{$this->dir}/cats.csv"));
        $this->assertSame([$before[0], $before[1] + 1], [Category::count(), Subcategory::count()]);

        $byName->refresh(); $bySlug->refresh(); $cat->refresh();
        $this->assertSame(["zz-$r-rug", "Tapiz$r", "Rugz$r"], [$byName->slug, $byName->getRawOriginal('name_fr'), $byName->getRawOriginal('name')], 'never renamed');
        $this->assertSame("سجاد $r", $byName->getRawOriginal('name_ar'), 'missing AR filled');
        $this->assertSame("Ancien FR $r", $bySlug->getRawOriginal('name_fr'));
        $this->assertSame("منزل $r", $cat->getRawOriginal('name_ar'));
        $this->assertSame("Maizon$r & Déco", $cat->getRawOriginal('name_fr'));

        $new = Subcategory::where('slug', "zz-$r-mirrors")->firstOrFail();
        $this->assertSame($cat->id, $new->category_id);

        $col = GenderMenuGroup::where(['category_id' => $cat->id, 'gender' => 'all'])->firstOrFail();
        $this->assertSame(3, $col->items()->count());

        // Second run: nothing new
        $counts = [Category::count(), Subcategory::count(), DB::table('gender_menu_items')->count(), DB::table('gender_menu_groups')->count()];
        $this->assertSame(0, $this->import("{$this->dir}/cats.csv"));
        $this->assertSame($counts, [Category::count(), Subcategory::count(), DB::table('gender_menu_items')->count(), DB::table('gender_menu_groups')->count()]);
    }

    public function test_ambiguous_items_block_the_import_until_decided(): void
    {
        $r = $this->r;
        $cat = $this->cat("zz-$r-mode", "Modz$r");
        $heels = $this->sub($cat, "zz-$r-high-heels", "High Hilz$r", "Talonz$r hauts");
        $csvPath = $this->csv([
            ["zz-$r-mode", "Modz$r", "Modz$r", '', 'Décoration', 1, "zz-$r-heels", "Talonz$r", "Hilz$r", '', ''],
            ["zz-$r-mode", "Modz$r", "Modz$r", '', 'Décoration', 2, "zz-$r-a", "Duplo$r", "Duplo$r A", '', ''],
            ["zz-$r-mode", "Modz$r", "Modz$r", '', 'Décoration', 3, "zz-$r-b", "Duplos$r", "Duplo$r B", '', ''],
        ]);
        $before = Subcategory::count();

        $this->assertSame(1, $this->import($csvPath), 'real run refuses while ambiguous');
        $this->assertSame($before, Subcategory::count());

        $decided = [
            "sub:zz-$r-mode/zz-$r-heels" => "match:zz-$r-mode/zz-$r-high-heels",
            "sub:zz-$r-mode/zz-$r-a" => 'new',
            "sub:zz-$r-mode/zz-$r-b" => "match:zz-$r-mode/zz-$r-high-heels",
        ];
        // Decided rows are settled, even when two of them point to the same existing subcategory
        $this->assertSame(0, $this->import($csvPath, $decided));
        $this->assertSame($before + 1, Subcategory::count());
        $this->assertFalse(Subcategory::where('slug', "zz-$r-heels")->exists());
        $this->assertSame("Talonz$r hauts", $heels->fresh()->getRawOriginal('name_fr'));
    }

    public function test_gendered_rows_get_femme_homme_links_and_attributes_copied_without_gender_elsewhere(): void
    {
        $r = $this->r;
        $gender = Attribute::firstOrCreate(['slug' => 'gender'], ['name' => 'Gender', 'type' => 'select']);
        $brand = Attribute::firstOrCreate(['slug' => 'brand'], ['name' => 'Brand', 'type' => 'text']);

        $mode = $this->cat("zz-$r-fashion", "Modo$r", true);
        $tshirt = $this->sub($mode, 't-shirt', "Tee$r", "Tee$r");
        DB::table('subcategory_attributes')->insert([
            ['subcategory_id' => $tshirt->id, 'attribute_id' => $gender->id, 'is_required' => 1, 'is_variant' => 0, 'order' => 0],
            ['subcategory_id' => $tshirt->id, 'attribute_id' => $brand->id, 'is_required' => 0, 'is_variant' => 0, 'order' => 1],
        ]);
        $other = $this->cat("zz-$r-garden", "Jardo$r");
        $tool = $this->sub($other, "zz-$r-tools", "Toolz$r", "Outilz$r");
        DB::table('subcategory_attributes')->insert([
            ['subcategory_id' => $tool->id, 'attribute_id' => $gender->id, 'is_required' => 0, 'is_variant' => 0, 'order' => 0],
            ['subcategory_id' => $tool->id, 'attribute_id' => $brand->id, 'is_required' => 0, 'is_variant' => 0, 'order' => 1],
        ]);

        $csvPath = $this->csv([
            ["zz-$r-fashion", "Modo$r", "Modo$r", '', 'Vêtements', 1, "zz-$r-skirts", "Jupz$r", "Skurtz$r", '', 'women'],
            ["zz-$r-fashion", "Modo$r", "Modo$r", '', 'Vêtements', 2, "zz-$r-polos", "Poloz$r", "Polloz$r", '', 'women;men'],
            ["zz-$r-garden", "Jardo$r", "Jardo$r", '', 'Jardin', 1, "zz-$r-pots", "Potz$r", "Pottz$r", '', ''],
        ]);
        $this->assertSame(0, $this->import($csvPath));

        $skirts = Subcategory::where('slug', "zz-$r-skirts")->firstOrFail();
        $polos = Subcategory::where('slug', "zz-$r-polos")->firstOrFail();
        $pots = Subcategory::where('slug', "zz-$r-pots")->firstOrFail();

        $menuGenders = fn ($s) => DB::table('gender_menu_items as i')->join('gender_menu_groups as g', 'g.id', '=', 'i.group_id')
            ->where('i.subcategory_id', $s->id)->pluck('g.gender')->sort()->values()->all();
        $this->assertSame(['women'], $menuGenders($skirts));
        $this->assertSame(['men', 'women'], $menuGenders($polos));
        $this->assertSame(['all'], $menuGenders($pots));

        $attrs = fn ($s) => DB::table('subcategory_attributes')->where('subcategory_id', $s->id)->pluck('attribute_id')->sort()->values()->all();
        $this->assertSame(collect([$gender->id, $brand->id])->sort()->values()->all(), $attrs($skirts), 'fashion copies T-shirt (with gender)');
        $this->assertSame([$brand->id], $attrs($pots), 'non-fashion: no gender attribute');

        // Products without a seller gender inherit: skirts → women, polos → unisex
        $seller = $this->makeUser('seller');
        $p1 = $this->makeProduct($seller, $mode, ['subcategory_id' => $skirts->id]);
        $p2 = $this->makeProduct($seller, $mode, ['subcategory_id' => $polos->id]);
        $this->assertSame('women', $p1->fresh()->gender);
        $this->assertSame('unisex', $p2->fresh()->gender);
    }

    public function test_storefront_menu_hides_empty_subcategories_and_categories(): void
    {
        $r = $this->r;
        $csvPath = $this->csv([
            ["zz-$r-pets", "Animo$r", "Animo$r", '', 'Jardin', 1, "zz-$r-dogs", "Chienz$r", "Dogz$r", '', ''],
            ["zz-$r-pets", "Animo$r", "Animo$r", '', 'Jardin', 2, "zz-$r-cats", "Chatz$r", "Catz$r", '', ''],
        ]);
        $this->assertSame(0, $this->import($csvPath));
        $cat = Category::where('slug', "zz-$r-pets")->firstOrFail();
        $dogs = Subcategory::where('slug', "zz-$r-dogs")->firstOrFail();

        $entry = fn () => collect($this->getJson('/api/categories/mega-menu')->assertOk()->json('data'))->firstWhere('slug', $cat->slug);
        $this->assertFalse($entry()['has_products']);
        $this->assertSame([], $entry()['groups']);

        // Seller form still sees every subcategory
        $this->assertCount(2, $this->getJson("/api/categories/{$cat->slug}/subcategories")->assertOk()->json('data'));

        $this->makeProduct($this->makeUser('seller'), $cat, ['subcategory_id' => $dogs->id]);
        Cache::flush();
        $e = $entry();
        $this->assertTrue($e['has_products']);
        $this->assertSame([$dogs->slug], array_column($e['groups'][0]['items'], 'slug'));
    }
}
