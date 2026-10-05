<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps image_fingerprints in step with one product: every photo (main and color photos)
 * gets a fingerprint; a product that is not live (or whose seller is inactive) has none, and
 * neither do the demo catalog's placeholder cards.
 *
 * A photo is only sent to the AI service when its file changed (sha1) or was made by another
 * model; identical files elsewhere in the catalog lend their vector.
 */
class ImageIndexer
{
    /** Demo catalog cards (product name on a colored template): not photos, never fingerprinted. */
    const PLACEHOLDERS = 'products/demo/%';

    public function __construct(private FingerprintClient $client, private FingerprintIndex $index) {}

    /**
     * @param  bool $force re-embed even unchanged photos
     * @return array{indexed: int, embedded: int, removed: bool, failed: int, changed: bool}
     * @throws SearchUnavailable when the AI service is unreachable (the job retries)
     */
    public function syncProduct(int $productId, bool $force = false, bool $bump = true): array
    {
        $product = Product::withTrashed()->find($productId);
        if (!$product || !self::isLive($product)) {
            // Force-deleted: its rows went with it (foreign keys), but the cached index still has them.
            $removed = DB::table('image_fingerprints')->where('product_id', $productId)->delete() + ($product ? 0 : 1);
            if ($removed && $bump) {
                $this->index->bump();
            }
            return ['indexed' => 0, 'embedded' => 0, 'removed' => true, 'failed' => 0, 'changed' => $removed > 0];
        }

        $images = DB::table('product_images')->where('product_id', $productId)
            ->where('image_path', 'NOT LIKE', self::PLACEHOLDERS)->get(['id', 'image_path']);
        $existing = DB::table('image_fingerprints')->where('product_id', $productId)
            ->get(['id', 'product_image_id', 'content_hash', 'model'])->keyBy('product_image_id');
        $model = $this->client->model();
        if ($model === null) {
            throw new SearchUnavailable('Photo service is down');
        }

        $disk = Storage::disk('public');
        $hashes = $todo = [];
        $failed = 0;
        foreach ($images as $img) {
            if (!$disk->exists($img->image_path)) {
                Log::info("[ImageSearch] Photo file missing, not indexed: {$img->image_path}");
                $failed++;
                continue;
            }
            $bytes = $disk->get($img->image_path);
            $hashes[$img->id] = $hash = sha1($bytes);
            $row = $existing[$img->id] ?? null;
            if ($force || !$row || $row->content_hash !== $hash || $row->model !== $model) {
                $todo[$img->id] = $bytes;
            }
        }

        // Same file already embedded with this model (another color, a duplicate upload): reuse it.
        $known = $force || !$todo ? collect() : DB::table('image_fingerprints')->where('model', $model)
            ->whereIn('content_hash', array_unique(array_intersect_key($hashes, $todo)))
            ->get(['content_hash', 'vector', 'color'])->keyBy('content_hash');

        // One request per distinct file (the same photo on two colors is sent once).
        $describe = [];
        foreach ($todo as $imageId => $bytes) {
            if (!isset($known[$hashes[$imageId]])) {
                $describe[$hashes[$imageId]] = $bytes;
            }
        }
        $fresh = $describe ? $this->client->describe($describe) : ['model' => $model, 'items' => []];

        $changed = false;
        $now = now();
        foreach ($todo as $imageId => $_) {
            if ($reuse = $known[$hashes[$imageId]] ?? null) {
                [$vector, $color, $rowModel] = [$reuse->vector, $reuse->color, $model];
            } elseif ($item = $fresh['items'][$hashes[$imageId]] ?? null) {
                [$vector, $color, $rowModel] = [Fingerprint::pack($item['vector']), $item['color'], $fresh['model'] ?? $model];
            } else {
                Log::warning("[ImageSearch] Unreadable photo, not indexed (product_images.id $imageId)");
                $failed++;
                continue;
            }
            DB::table('image_fingerprints')->updateOrInsert(
                ['product_image_id' => $imageId],
                ['product_id' => $productId, 'content_hash' => $hashes[$imageId], 'model' => $rowModel,
                 'vector' => $vector, 'color' => $color, 'created_at' => $now, 'updated_at' => $now],
            );
            $changed = true;
        }

        // Photos removed from the product (normally gone already through the foreign key).
        $stale = $existing->keys()->diff($images->pluck('id'))->all();
        if ($stale) {
            DB::table('image_fingerprints')->whereIn('product_image_id', $stale)->delete();
            $changed = true;
        }

        if ($changed && $bump) {
            $this->index->bump();
        }
        return ['indexed' => DB::table('image_fingerprints')->where('product_id', $productId)->count(),
                'embedded' => count(array_filter($fresh['items'])), 'removed' => false, 'failed' => $failed, 'changed' => $changed];
    }

    /** Searchable: approved, active, not deleted, and sold by an active seller. */
    public static function isLive(Product $product): bool
    {
        return $product->is_approved && $product->is_active && !$product->trashed()
            && (bool) DB::table('users')->where('id', $product->seller_id)->value('is_active');
    }
}
