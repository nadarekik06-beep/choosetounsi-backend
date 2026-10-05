<?php

namespace Tests\Feature\Search;

use App\Jobs\IndexProductImages;
use App\Models\ProductImage;
use App\Services\Search\BoilerplateFilter;
use App\Services\Search\Fingerprint;
use App\Services\Search\FingerprintClient;
use App\Services\Search\FingerprintIndex;
use App\Services\Search\ImageIndexer;
use App\Services\Search\ProductDocument;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/** php vendor/bin/phpunit tests/Feature/Search/IndexingTest.php */
class IndexingTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fakeTranslator();
        config(['search.semantic.enabled' => true, 'services.ai.url' => 'http://ai.test', 'search.meilisearch.host' => 'http://meili.test']);
    }

    public function test_document_has_normalized_names_translations_and_category(): void
    {
        $seller = $this->makeUser('seller');
        $cat = $this->makeCategory();
        $cat->forceFill(['name' => 'Shoes', 'name_fr' => 'Chaussures', 'name_ar' => 'أحذية'])->save();
        $p = $this->makeProduct($seller, $cat, ['name' => 'Évènement Sneakers']);
        DB::table('products')->where('id', $p->id)->update(['translations' => json_encode([
            'fr' => ['name' => 'Baskets Événement'], 'ar' => ['name' => 'حذاء رياضي'], 'en' => ['name' => 'Event Sneakers'],
        ], JSON_UNESCAPED_UNICODE)]);

        $doc = app(ProductDocument::class)->build($p->fresh());

        $this->assertSame('evenement sneakers', $doc['name']);
        $this->assertSame('baskets evenement', $doc['name_fr']);
        $this->assertSame('حذاء رياضي', $doc['name_ar']);
        $this->assertSame('event sneakers', $doc['name_en']);
        $this->assertStringContainsString('chaussures', $doc['category']);
        $this->assertStringContainsString('احذيه', $doc['category']);
        $this->assertSame('Évènement Sneakers', $doc['label']);
    }

    public function test_template_sentences_shared_by_many_products_are_dropped(): void
    {
        $seller = $this->makeUser('seller');
        $cat = $this->makeCategory();
        $template = 'Vous cherchez un produit qui allie qualité et fiabilité dans la catégorie Mode ? %s répond exactement à vos attentes.';
        foreach (range(1, 6) as $i) {
            $this->makeProduct($seller, $cat, ['description' => sprintf($template, "Produit $i")]);
        }
        $p = $this->makeProduct($seller, $cat, ['description' => sprintf($template, 'Robe rouge') . ' Tissu en lin, coupe longue.']);

        app(BoilerplateFilter::class)->forget();
        $doc = app(ProductDocument::class)->build($p);

        $this->assertStringNotContainsString('fiabilite', $doc['description']);
        $this->assertStringContainsString('robe rouge', $doc['description']);
        $this->assertStringContainsString('tissu en lin', $doc['description']);
    }

    public function test_text_vectors_are_attached(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        Http::fake(['ai.test/embed/text' => Http::response(['vectors' => [array_fill(0, 384, 0.05)]])]);

        $this->assertCount(384, app(ProductDocument::class)->buildMany([$p])[0]['_vectors']['text']);
    }

    public function test_products_are_indexed_keyword_only_when_the_embedder_is_down(): void
    {
        $p = $this->makeProduct($this->makeUser('seller'), $this->makeCategory());
        Http::fake(['ai.test/*' => Http::response('down', 503)]);

        $doc = app(ProductDocument::class)->buildMany([$p])[0];
        $this->assertNull($doc['_vectors']['text']);
        $this->assertNotEmpty($doc['name']);
    }

    public function test_every_photo_is_fingerprinted_once_as_a_normalized_float32_blob(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/a.jpg', 'A-bytes');
        Storage::disk('public')->put('products/b.jpg', 'B-bytes');
        $seller = $this->makeUser('seller');
        $p = $this->makeProduct($seller, $this->makeCategory());
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'products/a.jpg', 'is_primary' => true]);
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'products/b.jpg']);
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'products/a.jpg']);   // same file, another color

        Http::fake([
            'ai.test/health' => Http::response(['status' => 'ok', 'image_model' => 'clip-test@1']),
            'ai.test/embed/image' => Http::response(['model' => 'clip-test@1', 'vectors' => [array_fill(0, 512, 3.0), array_fill(0, 512, -2.0)],
                                                     'colors' => ['#112233', '#ffffff'], 'errors' => []]),
        ]);
        $v1 = app(FingerprintIndex::class)->version();

        $r = app(ImageIndexer::class)->syncProduct($p->id);

        $this->assertSame(3, $r['indexed']);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/embed/image') && count($req->data()) === 2);
        $rows = DB::table('image_fingerprints')->where('product_id', $p->id)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(2048, strlen($row->vector));
            $v = Fingerprint::unpack($row->vector);
            $this->assertEqualsWithDelta(1.0, Fingerprint::dot($v, $v), 1e-5, 'L2-normalized at insert');
            $this->assertSame('clip-test@1', $row->model);
        }
        $this->assertGreaterThan($v1, app(FingerprintIndex::class)->version(), 'cached index invalidated');

        // Second run: nothing changed, nothing sent to the AI service.
        Http::fake([
            'ai.test/health' => Http::response(['status' => 'ok', 'image_model' => 'clip-test@1']),
            'ai.test/embed/image' => Http::response('should not be called', 500),
        ]);
        Cache::forget(FingerprintClient::HEALTH_KEY);
        $this->assertSame(['indexed' => 3, 'embedded' => 0], array_intersect_key(app(ImageIndexer::class)->syncProduct($p->id), ['indexed' => 1, 'embedded' => 1]));
    }

    public function test_photos_of_a_product_that_is_not_live_are_removed(): void
    {
        $seller = $this->makeUser('seller');
        $p = $this->makeProduct($seller, $this->makeCategory());
        $img = ProductImage::create(['product_id' => $p->id, 'image_path' => 'products/x.jpg']);
        DB::table('image_fingerprints')->insert(['product_image_id' => $img->id, 'product_id' => $p->id, 'content_hash' => sha1('x'),
            'model' => 'm', 'vector' => Fingerprint::pack(array_fill(0, 512, 1.0))]);
        DB::table('products')->where('id', $p->id)->update(['is_active' => false]);

        $this->assertTrue(app(ImageIndexer::class)->syncProduct($p->id)['removed']);
        $this->assertSame(0, DB::table('image_fingerprints')->where('product_id', $p->id)->count());
    }

    public function test_photo_changes_and_status_changes_queue_image_indexing(): void
    {
        config(['search.indexing' => true]);
        Bus::fake([IndexProductImages::class]);
        $seller = $this->makeUser('seller');
        $p = $this->makeProduct($seller, $this->makeCategory());
        Bus::assertDispatched(IndexProductImages::class, fn ($job) => $job->productId === $p->id);

        // (The job is unique per product until it runs; faked jobs never run, so release the lock.)
        $release = fn () => Cache::lock('laravel_unique_job:' . IndexProductImages::class . $p->id)->forceRelease();
        $release();
        Bus::fake([IndexProductImages::class]);
        $p->update(['views' => 99]);
        Bus::assertNotDispatched(IndexProductImages::class);

        $p->update(['is_active' => false]);
        Bus::assertDispatched(IndexProductImages::class);

        $release();
        Bus::fake([IndexProductImages::class]);
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'products/x.jpg']);
        Bus::assertDispatched(IndexProductImages::class, fn ($job) => $job->productId === $p->id);
    }

    public function test_product_index_only_updates_on_searchable_changes(): void
    {
        $seller = $this->makeUser('seller');
        $p = $this->makeProduct($seller, $this->makeCategory());

        $p->update(['views' => 5]);
        $this->assertFalse($p->searchIndexShouldBeUpdated());
        $p->update(['name' => 'Renamed']);
        $this->assertTrue($p->searchIndexShouldBeUpdated());
        $p->update(['is_active' => false]);
        $this->assertFalse($p->shouldBeSearchable());
    }
}
