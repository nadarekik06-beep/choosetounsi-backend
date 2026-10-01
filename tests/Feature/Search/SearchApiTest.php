<?php

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * Search API against faked Meilisearch / embedding service responses.
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

    /** A real 8×8 JPEG (no GD needed). */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('q.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/2wBDARESEhgVGC8aGi9jQjhCY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2P/wAARCAAIAAgDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDHooorhPqD/9k='));
    }

    /** Meilisearch answers per query text; the embedding service returns a fixed vector. */
    private function fakeEngines(array $hitsByQuery, bool $embedder = true): void
    {
        Http::fake(function (Request $r) use ($hitsByQuery, $embedder) {
            if (str_starts_with($r->url(), 'http://ai.test')) {
                return $embedder ? Http::response(['vectors' => [array_fill(0, 384, 0.1)]]) : Http::response('down', 503);
            }
            if (str_contains($r->url(), '/products/search')) {
                return Http::response(['hits' => $hitsByQuery[$r['q']] ?? []]);
            }
            return Http::response([], 404);
        });
    }

    private function hit(int $id, float $score, array $fields = ['name']): array
    {
        return ['id' => $id, '_rankingScore' => $score, '_matchesPosition' => array_fill_keys($fields, [['start' => 0, 'length' => 3]])];
    }

    public function test_name_matches_come_first_and_semantic_only_noise_is_dropped(): void
    {
        $seller = $this->makeUser('seller');
        $shoes = $this->makeCategory();
        $named = $this->makeProduct($seller, $shoes, ['name' => 'Urban Sneakers']);
        $sameCat = $this->makeProduct($seller, $shoes, ['name' => 'Trail Runner']);
        $noise = $this->makeProduct($seller, $this->makeCategory(), ['name' => 'Harissa']);

        $this->fakeEngines(['sabbat' => [
            $this->hit($named->id, 0.95),
            $this->hit($sameCat->id, 0.80, ['category']),
            ['id' => $noise->id, '_rankingScore' => 0.41, '_matchesPosition' => []],   // vector-only, weak
        ]]);

        $res = $this->postJson('/api/search/text', ['query' => 'Sabbat'])->assertOk();

        $this->assertSame('ai', $res['source']);
        $this->assertSame([$named->id], array_column($res['sections']['direct'], 'id'));
        $this->assertSame([$sameCat->id], array_column($res['sections']['same_category'], 'id'));
        $this->assertSame([], $res['sections']['related']);
        $this->assertFalse($res['alternatives']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/products/search')
            && $r['hybrid']['embedder'] === 'text' && count($r['vector']) === 384);
    }

    public function test_query_is_keyword_only_when_the_embedder_is_down(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory(), ['name' => 'Casque audio']);
        $this->fakeEngines(['casque' => [$this->hit($p->id, 0.9)]], embedder: false);

        $this->postJson('/api/search/text', ['query' => 'casque'])->assertOk()->assertJsonPath('count', 1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/products/search') && !isset($r['vector']));
    }

    public function test_in_stock_and_better_rated_products_win_ties(): void
    {
        $seller = $this->makeUser('seller');
        $cat = $this->makeCategory();
        $soldOut = $this->makeProduct($seller, $cat, ['name' => 'Robe A', 'stock' => 0]);
        $inStock = $this->makeProduct($seller, $cat, ['name' => 'Robe B', 'stock' => 4]);
        $this->fakeEngines(['robe' => [$this->hit($soldOut->id, 0.9), $this->hit($inStock->id, 0.9)]]);

        $res = $this->postJson('/api/search/text', ['query' => 'robe'])->assertOk();
        $this->assertSame([$inStock->id, $soldOut->id], array_column($res['sections']['direct'], 'id'));
    }

    public function test_did_you_mean_searches_the_corrected_query(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory(), ['name' => 'Headphones Pro']);
        Cache::forget('search:vocabulary');
        $this->fakeEngines(['headphones' => [$this->hit($p->id, 0.9)]]);

        $res = $this->postJson('/api/search/text', ['query' => 'heaphonnes'])->assertOk();

        $this->assertSame('headphones', $res['did_you_mean']);
        $this->assertSame([$p->id], array_column($res['sections']['direct'], 'id'));
    }

    public function test_no_match_returns_close_alternatives_and_logs_the_query(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory(), ['name' => 'Olive Oil']);
        $this->fakeEngines(['xyzzy plop' => [['id' => $p->id, '_rankingScore' => 0.3, '_matchesPosition' => []]]]);

        $res = $this->postJson('/api/search/text', ['query' => 'Xyzzy plop'])->assertOk();

        $this->assertTrue($res['alternatives']);
        $this->assertSame([$p->id], array_column($res['sections']['related'], 'id'));
        $this->assertDatabaseHas('search_missed_queries', ['query' => 'xyzzy plop', 'results' => 0, 'day' => now()->toDateString()]);

        $this->postJson('/api/search/text', ['query' => 'xyzzy  PLOP'])->assertOk();
        $this->assertSame(2, (int) DB::table('search_missed_queries')->where('query', 'xyzzy plop')->value('searches'));
    }

    public function test_falls_back_to_mysql_with_synonyms_when_meilisearch_is_down(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'syn');
        file_put_contents($file, "honey, miel, 3sal\n");
        config(['search.synonyms_path' => $file]);
        $tag = 'Zq' . mt_rand(1000, 9999);
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory(), ['name' => "Thyme Honey $tag"]);
        Http::fake(['*' => Http::response('down', 500)]);

        $res = $this->postJson('/api/search/text', ['query' => "3sal $tag"])->assertOk();
        unlink($file);

        $this->assertSame('fallback', $res['source']);
        $this->assertContains($p->id, array_column($res['sections']['direct'], 'id'));
    }

    public function test_suggestions_use_the_storefront_language(): void
    {
        Http::fake(['meili.test/*' => Http::response(['hits' => [
            ['label' => 'Thyme Honey', 'label_fr' => 'Miel de thym', 'label_ar' => 'عسل الزعتر'],
        ]])]);
        $this->getJson('/api/search/suggestions?q=mie', ['Accept-Language' => 'fr'])
            ->assertOk()->assertJsonPath('suggestions', ['Miel de thym']);
    }

    public function test_suggestions_fall_back_to_mysql(): void
    {
        $name = 'Qwzt Lamp ' . mt_rand(1000, 9999);
        $this->makeProduct($this->makeUser('seller'), $this->makeCategory(), ['name' => $name]);
        Http::fake(['*' => Http::response('down', 500)]);
        $this->getJson('/api/search/suggestions?q=qwzt')->assertOk()->assertJsonPath('suggestions', [$name]);
    }

    public function test_image_search_dedupes_thresholds_and_boosts_the_majority_category(): void
    {
        $seller = $this->makeUser('seller');
        $shoes = $this->makeCategory();
        $other = $this->makeCategory();
        $same = $this->makeProduct($seller, $shoes);
        $similarShoe = $this->makeProduct($seller, $shoes);
        $otherCat = $this->makeProduct($seller, $other);
        $farAway = $this->makeProduct($seller, $other);
        config(['search.image.min_similarity' => 0.7, 'search.image.max_gap' => 0.2, 'search.image.category_boost' => 0.05]);

        $score = fn (float $cos) => (1 + $cos) / 2;
        Http::fake([
            'ai.test/embed/image' => Http::response(['vectors' => [array_fill(0, 512, 0.04)]]),
            'meili.test/*' => Http::response(['hits' => [
                ['product_id' => $same->id, '_rankingScore' => $score(0.92)],
                ['product_id' => $otherCat->id, '_rankingScore' => $score(0.80)],
                ['product_id' => $similarShoe->id, '_rankingScore' => $score(0.78)],
                ['product_id' => $farAway->id, '_rankingScore' => $score(0.60)],
            ]]),
        ]);

        $res = $this->post('/api/search/image', ['image' => $this->photo()], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame([$same->id, $similarShoe->id, $otherCat->id], array_column($res['products'], 'id'),
            'below-threshold photo dropped; same-category shoe overtakes the other category');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/product_images/search') && $r['hybrid']['embedder'] === 'clip');
    }

    public function test_image_search_reports_unavailable_cleanly(): void
    {
        Http::fake(['ai.test/*' => Http::response('down', 503)]);
        $this->post('/api/search/image', ['image' => $this->photo()], ['Accept' => 'application/json'])
            ->assertStatus(503)->assertJsonPath('success', false)
            ->assertJsonPath('message', __('messages.search.image_unavailable'));
    }

    public function test_image_with_nothing_similar_enough_returns_no_products(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        Http::fake([
            'ai.test/embed/image' => Http::response(['vectors' => [array_fill(0, 512, 0.04)]]),
            'meili.test/*' => Http::response(['hits' => [['product_id' => $p->id, '_rankingScore' => (1 + 0.55) / 2]]]),
        ]);
        $this->post('/api/search/image', ['image' => $this->photo()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('count', 0)->assertJsonPath('message', __('messages.search.no_similar'));
    }
}
