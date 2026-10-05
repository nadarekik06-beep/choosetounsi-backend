<?php

namespace Tests\Feature\Search;

use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * Search API: MySQL keyword search; faked Meilisearch / embedding service for the semantic fallback.
 * Photo search: ImageSearchTest.
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
        config([
            'services.ai.url' => 'http://ai.test', 'search.meilisearch.host' => 'http://meili.test',
            'search.semantic.enabled' => true, 'search.semantic.min_score' => 0.6,
        ]);
    }

    /** Meilisearch answers per query text; the embedding service returns a fixed vector. */
    private function fakeEngines(array $hitsByQuery, bool $embedder = true): void
    {
        Http::fake(function (Request $r) use ($hitsByQuery, $embedder) {
            if (str_starts_with($r->url(), 'http://ai.test')) {
                return $embedder ? Http::response(['vectors' => [array_fill(0, 384, 0.1)]]) : Http::response('down', 503);
            }
            if (str_ends_with($r->url(), '/multi-search')) {
                // [keyword query, vector query?]: keyword hits by query text, vector hits for the vector query.
                $keywordQuery = $r['queries'][0]['q'];
                $all = $hitsByQuery[$keywordQuery] ?? [];
                $isVector = fn ($h) => isset($h['_rankingScoreDetails']['vectorSort']);
                return Http::response(['results' => array_map(fn ($q) => ['hits' => array_values(array_filter($all,
                    fn ($h) => isset($q['vector']) ? $isVector($h) : !$isVector($h)))], $r['queries'])]);
            }
            return Http::response([], 404);
        });
    }

    private function hit(int $id, float $score, array $fields = ['name']): array
    {
        return ['id' => $id, '_rankingScore' => $score, '_rankingScoreDetails' => ['words' => ['score' => 1.0]],
                '_matchesPosition' => array_fill_keys($fields, [['start' => 0, 'length' => 3]])];
    }

    /** A hit found only through vectors (it may still contain a query word). */
    private function vectorHit(int $id, float $score): array
    {
        return ['id' => $id, '_rankingScore' => $score, '_rankingScoreDetails' => ['vectorSort' => ['similarity' => 2 * $score - 1]],
                '_matchesPosition' => ['name' => [['start' => 0, 'length' => 3]]]];
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

    public function test_nothing_matched_is_empty_and_logged_without_calling_the_ai_when_off(): void
    {
        config(['search.semantic.enabled' => false]);
        Http::fake();

        $res = $this->postJson('/api/search/text', ['query' => 'Xyzzy plop'])->assertOk();

        $this->assertSame(0, $res['count']);
        $this->assertFalse($res['alternatives']);
        $this->assertDatabaseHas('search_missed_queries', ['query' => 'xyzzy plop', 'results' => 0, 'day' => now()->toDateString()]);
        $this->postJson('/api/search/text', ['query' => 'xyzzy  PLOP'])->assertOk();
        $this->assertSame(2, (int) DB::table('search_missed_queries')->where('query', 'xyzzy plop')->value('searches'));
        Http::assertNothingSent();
    }

    public function test_semantic_search_is_only_a_fallback_shown_as_closest_products(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory(), ['name' => 'Qzolive Oil']);
        $this->fakeEngines(['' => [$this->vectorHit($p->id, 0.8)]]);

        // A keyword match never waits on the AI service
        $this->postJson('/api/search/text', ['query' => 'qzolive'])->assertOk()->assertJsonPath('count', 1);
        Http::assertNothingSent();

        $res = $this->postJson('/api/search/text', ['query' => 'zitoun bled'])->assertOk();
        $this->assertTrue($res['alternatives']);
        $this->assertSame('semantic', $res['source']);
        $this->assertSame([], $res['sections']['direct']);
        $this->assertSame([$p->id], array_column($res['sections']['related'], 'id'));
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
