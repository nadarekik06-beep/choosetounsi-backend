<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Keeps product_search_index (MySQL) in step with the catalog: one row of search tokens per
 * product, split by weight (names / category / brand & attributes / description).
 * Called by SearchIndexObserver on every relevant change; search:build-index rebuilds it all.
 */
class SearchIndexer
{
    const TABLE = 'product_search_index';
    const DESCRIPTION_WORDS = 60;

    public function __construct(
        private SearchText $text,
        private ProductDocument $document,
        private BoilerplateFilter $boilerplate,
        private DidYouMean $didYouMean,
    ) {}

    /** Re-index these products (rows of deleted products are removed). */
    public function refresh(array|int $productIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $productIds))));
        if (!$ids) {
            return;
        }

        $products = Product::whereIn('id', $ids)->with(ProductDocument::RELATIONS)->get();
        $rows = $products->map(fn (Product $p) => $this->row($p))->all();
        if ($rows) {
            DB::table(self::TABLE)->upsert($rows, ['product_id']);
        }
        $gone = array_diff($ids, $products->pluck('id')->all());
        if ($gone) {
            DB::table(self::TABLE)->whereIn('product_id', $gone)->delete();
        }
        $this->didYouMean->forget();
    }

    /** Products of a renamed category / subcategory. */
    public function refreshCategory(string $column, int $id): void
    {
        Product::where($column, $id)->orderBy('id')->select('id')
            ->chunk(200, fn ($chunk) => $this->refresh($chunk->pluck('id')->all()));
    }

    /** Full rebuild. @return int products indexed */
    public function rebuild(): int
    {
        $this->boilerplate->forget();
        $count = 0;
        Product::orderBy('id')->with(ProductDocument::RELATIONS)->chunk(200, function ($chunk) use (&$count) {
            DB::table(self::TABLE)->upsert($chunk->map(fn (Product $p) => $this->row($p))->all(), ['product_id']);
            $count += $chunk->count();
        });
        DB::table(self::TABLE)->whereNotIn('product_id', Product::select('id'))->delete();
        $this->didYouMean->forget();
        return $count;
    }

    public function row(Product $product): array
    {
        $raw = json_decode((string) $product->getRawOriginal('translations'), true);
        $raw = is_array($raw) ? $raw : [];

        // "| grey ralph lauren set | ensemble gris ralph lauren | طقم رمادي ralph lauren |"
        $names = [];
        foreach ([$product->getRawOriginal('name'), $raw['en']['name'] ?? null, $raw['fr']['name'] ?? null, $raw['ar']['name'] ?? null] as $name) {
            $tokens = $this->text->tokens($name);
            if ($tokens) {
                $names[implode(' ', $tokens)] = true;
            }
        }
        $names = $names ? '| ' . implode(' | ', array_keys($names)) . ' |' : '';

        $category = $this->text->padded(implode(' ', $this->document->categoryNames($product)));
        $extra = $this->text->padded(implode(' ', $this->document->attributeValues($product)));

        $description = [];
        foreach ([$product->getRawOriginal('short_description'), $product->getRawOriginal('description'),
                  $raw['fr']['short_description'] ?? null, $raw['en']['short_description'] ?? null] as $text) {
            $description[] = $this->boilerplate->clean($text);
        }
        $description = $this->text->tokens(implode(' ', $description));
        $description = $description ? ' ' . implode(' ', array_slice($description, 0, self::DESCRIPTION_WORDS)) . ' ' : '';

        return [
            'product_id'  => $product->id,
            'category_id' => $product->category_id,
            'names'       => $names,
            'category'    => $category,
            'extra'       => $extra,
            'description' => $description,
            'all_text'    => ' ' . trim("$names $category $extra $description") . ' ',
            'updated_at'  => now(),
        ];
    }
}
