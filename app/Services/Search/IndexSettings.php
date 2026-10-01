<?php

namespace App\Services\Search;

/**
 * Creates the two Meilisearch indexes and pushes their settings (idempotent).
 *
 *   products        one document per live product: keyword + typo + synonyms (+ text vectors)
 *   product_images  one document per product/variant photo: CLIP vectors, deduplicated per product
 */
class IndexSettings
{
    public function __construct(private MeiliClient $meili, private Synonyms $synonyms) {}

    public function products(): array
    {
        $settings = [
            'searchableAttributes' => ['name', 'name_en', 'name_fr', 'name_ar', 'category', 'attributes', 'description'],
            'displayedAttributes'  => ['id', 'label', 'label_fr', 'label_ar', 'category_id'],
            'filterableAttributes' => ['category_id', 'subcategory_id'],
            'sortableAttributes'   => [],
            'rankingRules'         => ['words', 'typo', 'proximity', 'attribute', 'exactness'],
            'synonyms'             => (object) $this->synonyms->forMeilisearch(),
            'typoTolerance'        => [
                'enabled'             => true,
                'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                // Model numbers and sizes must match exactly.
                'disableOnNumbers'    => true,
            ],
            'pagination'           => ['maxTotalHits' => 1000],
        ];

        // Semantic search off: drop the embedder (and its vectors) instead of keeping stale ones.
        $settings['embedders'] = ['text' => config('search.semantic.enabled')
            ? ['source' => 'userProvided', 'dimensions' => 384]
            : null];

        return $settings;
    }

    public function images(): array
    {
        return [
            'searchableAttributes' => ['product_id'],
            'displayedAttributes'  => ['id', 'product_id', 'variant_id'],
            'filterableAttributes' => ['product_id'],
            // One hit per product, even when several of its photos are close to the query.
            'distinctAttribute'    => 'product_id',
            'embedders'            => ['clip' => ['source' => 'userProvided', 'dimensions' => 512]],
        ];
    }

    /** @return array<string, array> index => finished settings task */
    public function sync(): array
    {
        $done = [];
        foreach (['products' => $this->products(), 'images' => $this->images()] as $index => $settings) {
            if (!$this->meili->indexExists($index)) {
                $this->meili->wait($this->meili->createIndex($index));
            }
            $done[$index] = $this->meili->wait($this->meili->updateSettings($index, $settings));
        }
        return $done;
    }
}
