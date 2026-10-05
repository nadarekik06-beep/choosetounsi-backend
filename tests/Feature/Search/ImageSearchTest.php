<?php

namespace Tests\Feature\Search;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Search\Fingerprint;
use App\Services\Search\FingerprintIndex;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * Search by photo on MySQL fingerprints, AI service faked (vectors are hand-made: the first
 * dimensions of a 512-dim unit vector, so similarities are easy to read).
 *
 * php vendor/bin/phpunit tests/Feature/Search/ImageSearchTest.php
 */
class ImageSearchTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeTranslator();
        config(['services.ai.url' => 'http://ai.test', 'search.image.per_minute' => 10]);
        $this->seller = $this->makeUser('seller');
    }

    /** Unit vector with the given leading components. */
    private function vec(float ...$head): array
    {
        return Fingerprint::normalize(array_pad($head, Fingerprint::DIMS, 0.0));
    }

    private function photoOf(Product $p, array $vector, ?string $color = null, ?int $colorOptionId = null): int
    {
        $imageId = DB::table('product_images')->insertGetId([
            'product_id' => $p->id, 'image_path' => 'products/test-' . Str::random(8) . '.jpg',
            'color_option_id' => $colorOptionId, 'is_primary' => $colorOptionId ? 0 : 1, 'order' => 0,
        ]);
        DB::table('image_fingerprints')->insert([
            'product_image_id' => $imageId, 'product_id' => $p->id, 'content_hash' => sha1((string) $imageId),
            'model' => 'clip-test@1', 'vector' => Fingerprint::pack($vector), 'color' => $color,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(FingerprintIndex::class)->bump();
        return $imageId;
    }

    private function fakeService(array $query, ?string $color = null): void
    {
        Http::fake([
            'ai.test/embed/query' => Http::response(['model' => 'clip-test@1', 'vector' => $query, 'color' => $color]),
            'ai.test/health'      => Http::response(['status' => 'ok', 'image_model' => 'clip-test@1']),
        ]);
    }

    private function photo(string $salt = 'a'): UploadedFile
    {
        // Any bytes: the (faked) service decides the vector. Different salt = different photo.
        return UploadedFile::fake()->createWithContent('q.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==') . $salt);
    }

    private function search(string $salt = 'a')
    {
        return $this->post('/api/search/image', ['image' => $this->photo($salt)], ['Accept' => 'application/json']);
    }

    private function colorOption(): int
    {
        $attr = DB::table('attributes')->insertGetId(['name' => 'Color', 'slug' => 'color-' . Str::random(8), 'type' => 'color']);
        return DB::table('attribute_options')->insertGetId(['attribute_id' => $attr, 'value' => 'Red', 'color_hex' => '#ff0000']);
    }

    public function test_exact_and_similar_sections_one_card_per_product_and_the_matched_color(): void
    {
        $dresses = $this->makeCategory();
        $other = $this->makeCategory();
        $red = $this->colorOption();

        $dress = $this->makeProduct($this->seller, $dresses);
        $this->photoOf($dress, $this->vec(1, 0, 0), '#101010');
        $redPhoto = $this->photoOf($dress, $this->vec(0.97, 0.243, 0), '#ee1111', $red);
        $close = $this->makeProduct($this->seller, $dresses);
        $this->photoOf($close, $this->vec(0.6, 0.8, 0), '#2030c0');
        $unrelated = $this->makeProduct($this->seller, $other);
        $this->photoOf($unrelated, $this->vec(0, 0, 1));

        $this->fakeService($this->vec(0.97, 0.243, 0), '#ff0000');
        $res = $this->search()->assertOk();

        $this->assertSame([$dress->id], array_column($res['sections']['exact'], 'id'), 'one card for the two photos of the dress');
        $this->assertSame([$close->id], array_column($res['sections']['similar'], 'id'));
        $card = $res['sections']['exact'][0];
        $this->assertSame($red, $card['matched_color_id'], 'the red photo matched: red is preselected');
        $path = DB::table('product_images')->where('id', $redPhoto)->value('image_path');
        $this->assertStringEndsWith($path, $card['image_url']);
        $this->assertSame('exact', $card['match']);
        $this->assertEqualsWithDelta(1.0, $card['similarity'], 0.001);
        $this->assertSame($dresses->id, $res['predicted_category']['id']);
        $this->assertFalse($res['fallback']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/embed/query'));
    }

    public function test_confident_category_keeps_out_other_categories_unless_near_identical(): void
    {
        config(['search.image.min_score' => 0.6, 'search.image.max_gap' => 0.5]);
        $bags = $this->makeCategory();
        $shoes = $this->makeCategory();
        foreach ([[1, 0.3], [1, 0.4], [1, 0.5]] as $head) {
            $this->photoOf($this->makeProduct($this->seller, $bags), $this->vec(...$head));
        }
        $shoe = $this->makeProduct($this->seller, $shoes);
        $this->photoOf($shoe, $this->vec(0.8, 0, 0.6));        // similarity 0.8 < outside_min
        foreach ([[0, 0, 1], [0.1, 0, 1]] as $head) {
            $this->photoOf($this->makeProduct($this->seller, $shoes), $this->vec(...$head));
        }

        $this->fakeService($this->vec(1, 0, 0));
        $res = $this->search()->assertOk();

        $this->assertSame($bags->id, $res['predicted_category']['id']);
        $this->assertTrue($res['predicted_category']['confident']);
        $ids = array_column(array_merge($res['sections']['exact'], $res['sections']['similar']), 'id');
        $this->assertCount(3, $ids);
        $this->assertNotContains($shoe->id, $ids, 'another category needs a near-identical photo');
    }

    public function test_nothing_close_enough_shows_the_predicted_category_instead(): void
    {
        $hats = $this->makeCategory();
        $cars = $this->makeCategory();
        $hat = $this->makeProduct($this->seller, $hats);
        $this->photoOf($hat, $this->vec(0.5, 0.866, 0));
        $this->photoOf($this->makeProduct($this->seller, $cars), $this->vec(0, 0, 1));

        $this->fakeService($this->vec(1, 0, 0));    // similarity 0.5: below every threshold
        $res = $this->search()->assertOk();

        $this->assertTrue($res['fallback']);
        $this->assertSame([], $res['sections']['exact']);
        $this->assertSame([$hat->id], array_column($res['sections']['similar'], 'id'));
        $this->assertSame($hats->id, $res['predicted_category']['id']);
    }

    public function test_products_of_inactive_sellers_and_offline_products_are_not_searchable(): void
    {
        $cat = $this->makeCategory();
        $live = $this->makeProduct($this->seller, $cat);
        $this->photoOf($live, $this->vec(1, 0.1));
        $banned = $this->makeUser('seller', ['is_active' => false]);
        $this->photoOf($this->makeProduct($banned, $cat), $this->vec(1, 0));
        $this->photoOf($this->makeProduct($this->seller, $cat, ['is_active' => false]), $this->vec(1, 0));

        $this->fakeService($this->vec(1, 0));
        $res = $this->search()->assertOk();
        $this->assertSame([$live->id], array_column($res['sections']['exact'], 'id'));
    }

    public function test_demo_placeholder_cards_are_not_photos(): void
    {
        $cat = $this->makeCategory();
        $real = $this->makeProduct($this->seller, $cat);
        $this->photoOf($real, $this->vec(1, 0.2));
        $demo = $this->makeProduct($this->seller, $cat);
        $card = $this->photoOf($demo, $this->vec(1, 0));
        DB::table('product_images')->where('id', $card)->update(['image_path' => 'products/demo/card.png']);
        app(FingerprintIndex::class)->bump();

        $this->fakeService($this->vec(1, 0));
        $res = $this->search()->assertOk();
        $this->assertSame([$real->id], array_column(array_merge($res['sections']['exact'], $res['sections']['similar']), 'id'));
    }

    public function test_results_are_cached_per_photo_until_the_catalog_changes_and_logged(): void
    {
        $p = $this->makeProduct($this->seller, $this->makeCategory());
        $this->photoOf($p, $this->vec(1, 0));
        $this->fakeService($this->vec(1, 0));

        $first = $this->search('same')->assertOk();
        $this->search('same')->assertOk();
        $this->assertSame([false, true], DB::table('image_search_logs')->orderBy('id')->pluck('cached')->map(fn ($c) => (bool) $c)->all());
        Http::assertSentCount(1);

        app(FingerprintIndex::class)->bump();
        $this->search('same')->assertOk();
        $this->assertFalse((bool) DB::table('image_search_logs')->orderByDesc('id')->value('cached'), 'a catalog change drops cached results');

        $log = DB::table('image_search_logs')->find($first['search_id']);
        $this->assertSame([[$p->id, 1, 1]], array_map(fn ($r) => [$r[0], (int) round($r[1]), (int) round($r[2])], json_decode($log->top_scores, true)));

        $this->postJson('/api/search/image/click', ['search_id' => $first['search_id'], 'product_id' => $p->id, 'rank' => 1])->assertOk();
        $this->assertSame($p->id, (int) DB::table('image_search_logs')->where('id', $first['search_id'])->value('clicked_product_id'));
    }

    public function test_service_down_answers_unavailable_at_once_and_text_search_still_works(): void
    {
        $p = $this->makeProduct($this->seller, $this->makeCategory(), ['name' => 'Robe rouge soirée']);
        $this->photoOf($p, $this->vec(1, 0));
        Http::fake(['ai.test/*' => Http::response('down', 500)]);

        $this->search('one')->assertStatus(503)->assertJsonPath('code', 'unavailable')
            ->assertJsonPath('message', __('messages.search.image_unavailable'));
        $this->search('two')->assertStatus(503);
        Http::assertSentCount(1);   // marked down: the second search didn't wait for the service

        $this->getJson('/api/search/image/status')->assertOk()->assertJsonPath('available', false);
        $this->postJson('/api/search/text', ['query' => 'robe rouge'])->assertOk()
            ->assertJsonPath('sections.direct.0.id', $p->id);
    }

    public function test_status_reports_available_when_the_service_and_photos_are_there(): void
    {
        $this->fakeService($this->vec(1));
        $this->getJson('/api/search/image/status')->assertJsonPath('available', DB::table('image_fingerprints')->exists());
        $this->photoOf($this->makeProduct($this->seller, $this->makeCategory()), $this->vec(1));
        $this->getJson('/api/search/image/status')->assertJsonPath('available', true);
    }

    public function test_photo_search_is_rate_limited(): void
    {
        config(['search.image.per_minute' => 3]);
        $this->photoOf($this->makeProduct($this->seller, $this->makeCategory()), $this->vec(1));
        $this->fakeService($this->vec(1));
        foreach (range(1, 3) as $i) {
            $this->search("r$i")->assertOk();
        }
        $this->search('r4')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_fingerprints_are_unit_float32_blobs(): void
    {
        $blob = Fingerprint::pack(array_fill(0, Fingerprint::DIMS, 3.0));
        $this->assertSame(2048, strlen($blob));
        $v = Fingerprint::unpack($blob);
        $this->assertEqualsWithDelta(1.0, Fingerprint::dot($v, $v), 1e-5);
        $this->assertLessThan(3, Fingerprint::colorDistance(Fingerprint::lab('#ff0000'), Fingerprint::lab('#fe0101')));
        $this->assertGreaterThan(50, Fingerprint::colorDistance(Fingerprint::lab('#ff0000'), Fingerprint::lab('#0000ff')));
    }
}
