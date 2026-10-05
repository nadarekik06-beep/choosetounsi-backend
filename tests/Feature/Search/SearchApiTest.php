<?php

namespace Tests\Feature\Search;

use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * Search API: MySQL keyword search (photo search: ImageSearchTest).
 * php vendor/bin/phpunit tests/Feature/Search/SearchApiTest.php
 */
class SearchApiTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeTranslator();
        config(['services.ai.url' => 'http://ai.test']);
    }

    /** Synonym file for one test. */
    private function synonymsFile(string $content): void
    {
        $file = tempnam(sys_get_temp_dir(), 'syn');
        file_put_contents($file, $content);
        config(['search.synonyms_path' => $file]);
        $this->beforeApplicationDestroyed(fn () => @unlink($file));
    }

    /** @return array{0: Category, 1: Category} clothing (named "Ensembles" in French) and home; "set" = "ensemble" for clothing only */
    private function clothingAndHome(): array
    {
        $clothing = $this->makeCategory();
        $clothing->update(['name' => 'Sets', 'name_fr' => 'Ensembles', 'name_ar' => 'طقم']);
        $home = $this->makeCategory();
        $this->synonymsFile("ensemble, set, tenue  @ {$clothing->slug}\n");
        return [$clothing, $home];
    }

    public function test_name_and_category_matches_rank_first_and_out_of_context_synonyms_are_ignored(): void
    {
        [$clothing, $home] = $this->clothingAndHome();
        $seller  = $this->makeUser('seller');
        $named   = $this->makeProduct($seller, $clothing, ['name' => 'Ensemble lin beige']);
        $polo    = $this->makeProduct($seller, $clothing, ['name' => 'Polo set']);
        $plates  = $this->makeProduct($seller, $home, ['name' => 'Ceramic Plate Set']);
        $headset = $this->makeProduct($seller, $home, ['name' => 'Audio Headset']);
        $vase    = $this->makeProduct($seller, $home, ['name' => 'Glass Vase', 'description' => 'Un joli ensemble de vases en verre']);
        $mine    = [$named->id, $polo->id, $plates->id, $headset->id, $vase->id];

        $res = $this->postJson('/api/search/text', ['query' => 'Ensembles'])->assertOk();

        $direct = array_column($res['sections']['direct'], 'id');
        $this->assertSame([$named->id, $polo->id], array_values(array_intersect($direct, $mine)));
        $all = array_merge($direct, array_column($res['sections']['same_category'], 'id'), array_column($res['sections']['related'], 'id'));
        $this->assertNotContains($plates->id, $all, '"set" only means "ensemble" for clothing');
        $this->assertNotContains($headset->id, $all, 'no substring matches ("headset")');
        $this->assertContains($vase->id, array_column($res['sections']['related'], 'id'), 'a description-only match is a weak, related result');
        $this->assertSame('keyword', $res['source']);
    }

    public function test_misspelled_words_are_corrected_against_the_catalog_unless_exact(): void
    {
        [$clothing] = $this->clothingAndHome();
        $p = $this->makeProduct($this->makeUser('seller'), $clothing, ['name' => 'Ensemble jogging gris']);

        $res = $this->postJson('/api/search/text', ['query' => 'ensembel'])->assertOk();
        $this->assertSame('ensemble', $res['did_you_mean']);
        $this->assertContains($p->id, array_column($res['sections']['direct'], 'id'));

        $exact = $this->postJson('/api/search/text', ['query' => 'ensembel', 'exact' => true])->assertOk();
        $this->assertNull($exact['did_you_mean']);
        $this->assertSame([], $exact['sections']['direct']);
    }

    public function test_accents_plurals_stop_words_and_tshirt_spellings_match(): void
    {
        $seller = $this->makeUser('seller');
        $cat  = $this->makeCategory();
        $robe = $this->makeProduct($seller, $cat, ['name' => 'Robe soirée Qzvelour']);
        $tee  = $this->makeProduct($seller, $cat, ['name' => 'T-Shirt Qzcoton']);

        foreach (['robes de soiree qzvelour', 'ROBE SOIRÉE Qzvelour'] as $q) {
            $this->assertContains($robe->id, array_column($this->postJson('/api/search/text', ['query' => $q])['sections']['direct'], 'id'), $q);
        }
        foreach (['tshirt qzcoton', 't shirt qzcoton', 'tee-shirt qzcoton'] as $q) {
            $this->assertContains($tee->id, array_column($this->postJson('/api/search/text', ['query' => $q])['sections']['direct'], 'id'), $q);
        }
    }

    public function test_in_stock_products_win_ties(): void
    {
        $seller  = $this->makeUser('seller');
        $cat     = $this->makeCategory();
        $soldOut = $this->makeProduct($seller, $cat, ['name' => 'Qzrobe A', 'stock' => 0]);
        $inStock = $this->makeProduct($seller, $cat, ['name' => 'Qzrobe B', 'stock' => 4]);

        $res = $this->postJson('/api/search/text', ['query' => 'qzrobe'])->assertOk();
        $this->assertSame([$inStock->id, $soldOut->id], array_column($res['sections']['direct'], 'id'));
    }

    public function test_nothing_matched_is_empty_and_logged_without_calling_the_ai_service(): void
    {
        Http::fake();

        $res = $this->postJson('/api/search/text', ['query' => 'Xyzzy plop'])->assertOk();

        $this->assertSame(0, $res['count']);
        $this->assertSame('keyword', $res['source']);
        $this->assertDatabaseHas('search_missed_queries', ['query' => 'xyzzy plop', 'results' => 0, 'day' => now()->toDateString()]);
        $this->postJson('/api/search/text', ['query' => 'xyzzy  PLOP'])->assertOk();
        $this->assertSame(2, (int) DB::table('search_missed_queries')->where('query', 'xyzzy plop')->value('searches'));
        Http::assertNothingSent();
    }

    public function test_a_shop_name_finds_the_shop_and_its_products(): void
    {
        $seller = $this->makeUser('seller', ['name' => 'Qzdar Elmoda Shop']);
        $cat = $this->makeCategory();
        $a = $this->makeProduct($seller, $cat, ['name' => 'Robe longue']);
        $b = $this->makeProduct($seller, $cat, ['name' => 'Sac cabas']);
        $this->makeProduct($this->makeUser('seller'), $cat, ['name' => 'Autre chose']);
        app(\App\Services\Search\Shops::class)->forget();

        $res = $this->postJson('/api/search/text', ['query' => 'qzdar elmoda shop'])->assertOk();
        $this->assertSame($seller->id, $res['shops'][0]['id']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($res['sections']['direct'], 'id'));

        // Part of the name, no product named like it: still the shop's products
        $this->assertCount(2, $this->postJson('/api/search/text', ['query' => 'qzdar'])->json('sections.direct'));

        // Unfinished word: the shop is suggested, its products are not dumped as results
        $this->getJson('/api/search/suggestions?q=qzda')->assertOk()->assertJsonPath('shops.0.id', $seller->id);
        $res = $this->postJson('/api/search/text', ['query' => 'qzda'])->assertOk();
        $this->assertSame($seller->id, $res['shops'][0]['id']);
        $this->assertSame([], $res['sections']['direct']);
    }

    public function test_search_still_answers_when_the_ai_services_are_down(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory(), ['name' => 'Qzhoney jar']);
        Http::fake(['*' => Http::response('down', 500)]);

        $this->postJson('/api/search/text', ['query' => 'qzhoney'])->assertOk()->assertJsonPath('sections.direct.0.id', $p->id);
        $this->postJson('/api/search/text', ['query' => 'nothing like it'])->assertOk()->assertJsonPath('count', 0);
    }

    public function test_suggestions_use_the_storefront_language(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory(), ['name' => 'Qzthyme Honey']);
        DB::table('products')->where('id', $p->id)->update(['translations' => json_encode(['fr' => ['name' => 'Miel de qzthym']])]);
        app(\App\Services\Search\SearchIndexer::class)->refresh($p->id);

        $this->getJson('/api/search/suggestions?q=' . urlencode('miel qz'), ['Accept-Language' => 'fr'])
            ->assertOk()->assertJsonPath('suggestions', ['Miel de qzthym']);
    }
}
