<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the product_images index in step with one product: every photo (product and
 * variant photos alike) gets a CLIP vector; photos of products that are not live are removed.
 *
 * Vectors are stored in search_image_embeddings keyed by file content, so they are computed
 * once per file, reused when Meilisearch is rebuilt, and shared by identical files.
 */
class ImageIndexer
{
    public function __construct(private MeiliClient $meili, private EmbeddingClient $embeddings) {}

    /**
     * @return array{indexed: int, removed: bool, failed: int}
     * @throws SearchUnavailable when Meilisearch or the embedding service is unreachable (job retries)
     */
    public function syncProduct(int $productId): array
    {
        $product = Product::withTrashed()->find($productId);
        if (!$product || !self::isLive($product)) {
            $this->meili->deleteByFilter('images', "product_id = $productId");
            return ['indexed' => 0, 'removed' => true, 'failed' => 0];
        }

        $rows = DB::table('product_images')->where('product_id', $productId)->get(['id', 'variant_id', 'image_path']);
        $vectors = $this->vectorsFor($rows->all());

        $docs = [];
        foreach ($rows as $row) {
            if (isset($vectors[$row->id])) {
                $docs[] = [
                    'id'         => $row->id,
                    'product_id' => $productId,
                    'variant_id' => $row->variant_id,
                    '_vectors'   => ['clip' => $vectors[$row->id]],
                ];
            }
        }

        // Replace the product's photos: removed images disappear, changed ones are re-embedded.
        $this->meili->deleteByFilter('images', "product_id = $productId");
        if ($docs) {
            $this->meili->addDocuments('images', $docs);
        }

        return ['indexed' => count($docs), 'removed' => false, 'failed' => $rows->count() - count($docs)];
    }

    public static function isLive(Product $product): bool
    {
        return $product->is_approved && $product->is_active && !$product->trashed();
    }

    /**
     * @param  object[] $rows product_images rows
     * @return array<int, array> product_images.id => vector (unreadable/missing files left out)
     */
    public function vectorsFor(array $rows): array
    {
        $model = $this->embeddings->imageModel();
        $disk = Storage::disk('public');

        $hashes = $bytes = [];
        foreach ($rows as $row) {
            if (!$disk->exists($row->image_path)) {
                Log::info("[Search] Image file missing, not indexed: {$row->image_path}");
                continue;
            }
            $data = $disk->get($row->image_path);
            $hashes[$row->id] = sha1($data);
            $bytes[$hashes[$row->id]] = $data;
        }

        $known = DB::table('search_image_embeddings')->where('model', $model)
            ->whereIn('content_hash', array_unique($hashes))->pluck('vector', 'content_hash')
            ->map(fn ($v) => json_decode($v, true))->all();

        $todo = array_diff_key($bytes, $known);
        if ($todo) {
            foreach ($this->embeddings->imageVectors($todo) as $hash => $vector) {
                if ($vector === null) {
                    Log::warning("[Search] Unreadable image, not indexed (sha1 $hash)");
                    continue;
                }
                DB::table('search_image_embeddings')->insertOrIgnore([
                    'content_hash' => $hash, 'model' => $model,
                    'vector' => json_encode($vector), 'created_at' => now(),
                ]);
                $known[$hash] = $vector;
            }
        }

        $out = [];
        foreach ($hashes as $imageId => $hash) {
            if (isset($known[$hash])) {
                $out[$imageId] = $known[$hash];
            }
        }
        return $out;
    }
}
