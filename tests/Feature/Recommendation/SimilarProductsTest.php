<?php

namespace Tests\Feature\Recommendation;

use App\Services\Recommendation\SimilarProductsFinder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use App\Services\Search\Fingerprint;
use App\Services\Search\FingerprintIndex;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "You might also like": photo fingerprints + content fallback.
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

    /** A fingerprinted main photo, the leading components of a unit vector. */
    private function photo(\App\Models\Product $p, float ...$head): void
    {
        $imageId = DB::table('product_images')->insertGetId(['product_id' => $p->id, 'image_path' => "products/t-{$p->id}.jpg", 'is_primary' => 1, 'order' => 0]);
        DB::table('image_fingerprints')->insert(['product_image_id' => $imageId, 'product_id' => $p->id, 'content_hash' => sha1("t{$p->id}"),
            'model' => 'clip-test@1', 'vector' => Fingerprint::pack(array_pad($head, Fingerprint::DIMS, 0.0))]);
        app(FingerprintIndex::class)->bump();
    }

    public function test_photo_similarity_is_blended_with_content_similarity(): void
    {
        $seller = $this->makeUser('seller');
        $shoes  = $this->makeCategory();
        $phones = $this->makeCategory();
        $seed   = $this->makeProduct($seller, $shoes);
        $sameCat = $this->makeProduct($seller, $shoes);
        $aiOnly  = $this->makeProduct($seller, $phones);
        $this->photo($seed, 1, 0);
        $this->photo($aiOnly, 0.95, 0.31);     // cosine 0.95
        $this->photo($sameCat, 0.85, 0.53);    // cosine 0.85

        $out = app(SimilarProductsFinder::class)->similarTo([$seed->id => 1.0]);

        $this->assertSame('ai', $out['source']);
        $this->assertSame([$sameCat->id, $aiOnly->id], array_keys(array_slice($out['scores'], 0, 2, true)), 'photo + same category beats photo alone');
        $this->assertSame($seed->id, $out['seeds_of'][$aiOnly->id]);
    }

    public function test_falls_back_to_content_similarity_without_photos(): void
    {
        $seller = $this->makeUser('seller');
        $cat    = $this->makeCategory();
        $seed   = $this->makeProduct($seller, $cat);
        $other  = $this->makeProduct($seller, $cat);

        $out = app(SimilarProductsFinder::class)->similarTo([$seed->id => 1.0]);
        $this->assertSame('fallback', $out['source']);
        $this->assertArrayHasKey($other->id, $out['scores']);
    }

    public function test_feed_similar_row_uses_ai_and_drops_unavailable_products(): void
    {
        $user   = $this->makeUser();
        $seller = $this->makeUser('seller');
        $viewedCat = $this->makeCategory();
        $viewed = array_map(fn () => $this->makeProduct($seller, $viewedCat), range(1, 2));
        $targets = array_map(fn () => $this->makeProduct($this->makeUser('seller'), $this->makeCategory()), range(1, 6));
        $gone = $this->makeProduct($seller, $this->makeCategory(), ['stock' => 0]);
        // A standard-size catalog around them (strict rules: each product once).
        config(['recommendations.feed.small_catalog.max_products' => 0, 'recommendations.feed.small_catalog.min_sellers' => 0]);
        foreach (range(1, 40) as $_) {
            $this->makeProduct($this->makeUser('seller'), $viewedCat);
        }

        foreach ($viewed as $p) {
            \App\Models\UserInteraction::create(['user_id' => $user->id, 'product_id' => $p->id, 'seller_id' => $p->seller_id,
                'category_id' => $p->category_id, 'event_type' => 'cart_add']);
        }
        foreach ($viewed as $p) {
            $this->photo($p, 1, 0);
        }
        foreach ($targets as $i => $p) {
            $this->photo($p, 1, 0.1 + $i * 0.05);   // close to the viewed products' photos
        }
        $this->photo($gone, 1, 0.05);

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
