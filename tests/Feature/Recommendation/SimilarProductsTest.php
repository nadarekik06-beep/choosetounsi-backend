<?php

namespace Tests\Feature\Recommendation;

use App\Services\Recommendation\SimilarProductsFinder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "You might also like": AI service + content fallback.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Recommendation/SimilarProductsTest.php
 */
class SimilarProductsTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeTranslator();
    }

    public function test_ai_scores_are_blended_with_content_similarity(): void
    {
        $seller = $this->makeUser('seller');
        $shoes  = $this->makeCategory();
        $phones = $this->makeCategory();
        $seed   = $this->makeProduct($seller, $shoes);
        $sameCat   = $this->makeProduct($seller, $shoes);
        $aiOnly    = $this->makeProduct($seller, $phones);

        Http::fake(['*/similar' => Http::response(['results' => [
            ['product_id' => $aiOnly->id,  'score' => 0.8, 'seed_id' => $seed->id],
            ['product_id' => $sameCat->id, 'score' => 0.7, 'seed_id' => $seed->id],
        ], 'seeds_used' => [$seed->id]])]);

        $out = app(SimilarProductsFinder::class)->similarTo([$seed->id => 1.0]);

        $this->assertSame('ai', $out['source']);
        $this->assertSame([$sameCat->id, $aiOnly->id], array_keys($out['scores']), 'AI + same category beats AI alone');
        $this->assertSame($seed->id, $out['seeds_of'][$aiOnly->id]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/similar')
            && (array) $r['seeds'] == [$seed->id => 1.0]);
    }

    public function test_falls_back_to_content_similarity_and_backs_off_when_ai_is_down(): void
    {
        $seller = $this->makeUser('seller');
        $cat    = $this->makeCategory();
        $seed   = $this->makeProduct($seller, $cat);
        $other  = $this->makeProduct($seller, $cat);

        Http::fake(['*/similar' => Http::response('boom', 500)]);
        $finder = app(SimilarProductsFinder::class);

        $first = $finder->similarTo([$seed->id => 1.0]);
        $this->assertSame('fallback', $first['source']);
        $this->assertArrayHasKey($other->id, $first['scores']);

        $finder->similarTo([$seed->id => 1.0]);
        Http::assertSentCount(1);   // second call skipped the service entirely
    }

    public function test_ai_results_are_cached_per_seed_set(): void
    {
        $seller = $this->makeUser('seller');
        $seed   = $this->makeProduct($seller, $this->makeCategory());
        Http::fake(['*/similar' => Http::response(['results' => [], 'seeds_used' => []])]);

        $finder = app(SimilarProductsFinder::class);
        $finder->similarTo([$seed->id => 1.0]);
        $finder->similarTo([$seed->id => 1.0]);

        Http::assertSentCount(1);
    }

    public function test_feed_similar_row_uses_ai_and_drops_unavailable_products(): void
    {
        $user   = $this->makeUser();
        $seller = $this->makeUser('seller');
        $viewedCat = $this->makeCategory();
        $viewed = array_map(fn () => $this->makeProduct($seller, $viewedCat), range(1, 2));
        $targets = array_map(fn () => $this->makeProduct($this->makeUser('seller'), $this->makeCategory()), range(1, 6));
        $gone = $this->makeProduct($seller, $this->makeCategory(), ['stock' => 0]);

        foreach ($viewed as $p) {
            \App\Models\UserInteraction::create(['user_id' => $user->id, 'product_id' => $p->id, 'seller_id' => $p->seller_id,
                'category_id' => $p->category_id, 'event_type' => 'cart_add']);
        }
        $results = array_map(fn ($p, $i) => ['product_id' => $p->id, 'score' => 0.9 - $i * 0.05, 'seed_id' => $viewed[0]->id], $targets, array_keys($targets));
        $results[] = ['product_id' => $gone->id, 'score' => 0.95, 'seed_id' => $viewed[0]->id];
        Http::fake(['*/similar' => Http::response(['results' => $results, 'seeds_used' => [$viewed[0]->id]])]);

        $token = $user->createToken('t')->plainTextToken;
        $feed  = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/home/feed')->assertOk()->json();

        $row = collect($feed['sections'])->firstWhere('key', 'similar');
        $this->assertNotNull($row);
        $ids = array_column($row['products'], 'id');
        $this->assertNotContains($gone->id, $ids);
        // AI-only matches (different categories from the seeds) make it into the row.
        $this->assertGreaterThanOrEqual(4, count(array_intersect(array_column($targets, 'id'), $ids)));
    }
}
