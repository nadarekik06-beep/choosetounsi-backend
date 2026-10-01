<?php

namespace Tests\Feature\Search;

use App\Jobs\IndexProductImages;
use App\Models\ProductImage;
use App\Services\Search\BoilerplateFilter;
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

    public function test_every_photo_is_embedded_once_and_replaced_in_the_image_index(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/a.jpg', 'A-bytes');
        Storage::disk('public')->put('products/b.jpg', 'B-bytes');
        $seller = $this->makeUser('seller');
        $p = $this->makeProduct($seller, $this->makeCategory());
        $main = ProductImage::create(['product_id' => $p->id, 'image_path' => 'products/a.jpg', 'is_primary' => true]);
        $variantPhoto = ProductImage::create(['product_id' => $p->id, 'image_path' => 'products/b.jpg']);
        $dupe = ProductImage::create(['product_id' => $p->id, 'image_path' => 'products/a.jpg']);

        Http::fake([
            'ai.test/embed/image' => Http::response(['vectors' => [array_fill(0, 512, 0.1), array_fill(0, 512, 0.2)], 'errors' => []]),
            'meili.test/*' => Http::response(['taskUid' => 1], 202),
        ]);

        $r = app(ImageIndexer::class)->syncProduct($p->id);
        $this->assertSame(3, $r['indexed']);

        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/documents/delete')
            && $req['filter'] === "product_id = {$p->id}");
        Http::assertSent(function (Request $req) use ($main, $variantPhoto, $dupe) {
            if (!str_contains($req->url(), 'product_images/documents?primaryKey=id')) {
                return false;
            }
            $ids = array_column($req->data(), 'id');
            sort($ids);
            return $ids === [$main->id, $variantPhoto->id, $dupe->id];
        });
        $this->assertSame(2, DB::table('search_image_embeddings')->whereIn('content_hash', [sha1('A-bytes'), sha1('B-bytes')])->count(),
            'identical files share one vector');

        // Second run: nothing to embed.
        Http::fake(['ai.test/*' => Http::response('should not be called', 500), 'meili.test/*' => Http::response(['taskUid' => 2], 202)]);
        $this->assertSame(3, app(ImageIndexer::class)->syncProduct($p->id)['indexed']);
    }

    public function test_photos_of_a_product_that_is_not_live_are_removed(): void
    {
        $seller = $this->makeUser('seller');
        $p = $this->makeProduct($seller, $this->makeCategory(), ['is_active' => false]);
        Http::fake(['meili.test/*' => Http::response(['taskUid' => 1], 202)]);

        $this->assertTrue(app(ImageIndexer::class)->syncProduct($p->id)['removed']);
        Http::assertSentCount(1);
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
