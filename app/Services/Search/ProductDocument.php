<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * The Meilisearch document of a product. It only serves matching: what a result looks like
 * (price, stock, image, localized name) is always read live from MySQL afterwards.
 *
 * Searchable text is normalized (see QueryNormalizer) and ordered by importance:
 * names (original + FR/AR/EN translations) > category > attributes/variant options >
 * description with the template boilerplate removed.
 */
class ProductDocument
{
    const RELATIONS = ['category', 'subcategory', 'attributeValues.attribute', 'activeVariants'];
    const DESCRIPTION_WORDS = 60;

    public function __construct(
        private QueryNormalizer $normalizer,
        private BoilerplateFilter $boilerplate,
        private EmbeddingClient $embeddings,
    ) {}

    public function build(Product $product): array
    {
        $product->loadMissing(self::RELATIONS);
        $raw = $this->rawTranslations($product);
        $n = fn (?string $s) => $this->normalizer->normalize($s);

        $description = implode(' ', array_slice(
            explode(' ', $this->boilerplate->clean($product->getRawOriginal('description'))),
            0, self::DESCRIPTION_WORDS
        ));

        return [
            'id'             => $product->id,
            'name'           => $n($product->getRawOriginal('name')),
            'name_en'        => $n($raw['en']['name'] ?? null),
            'name_fr'        => $n($raw['fr']['name'] ?? null),
            'name_ar'        => $n($raw['ar']['name'] ?? null),
            'category'       => $n(implode(' ', $this->categoryNames($product))),
            'attributes'     => $n(implode(' ', $this->attributeValues($product))),
            'description'    => $description,
            'category_id'    => $product->category_id,
            'subcategory_id' => $product->subcategory_id,
            // Display only (autocomplete), never searched.
            'label'          => $product->getRawOriginal('name'),
            'label_fr'       => $raw['fr']['name'] ?? null,
            'label_ar'       => $raw['ar']['name'] ?? null,
        ];
    }

    /** Text the multilingual model embeds: names, category, attributes, start of the real description. */
    public function semanticText(Product $product): string
    {
        $product->loadMissing(self::RELATIONS);
        $raw = $this->rawTranslations($product);
        $names = array_unique(array_filter([
            $product->getRawOriginal('name'), $raw['en']['name'] ?? null, $raw['fr']['name'] ?? null,
        ]));
        $category = array_filter([$product->category?->getRawOriginal('name'), $product->subcategory?->getRawOriginal('name')]);
        $description = implode(' ', array_slice(explode(' ', $this->boilerplate->clean($product->getRawOriginal('description'))), 0, 30));

        return mb_substr(implode('. ', array_filter([
            implode(' / ', $names),
            implode(' > ', $category),
            implode(', ', $this->attributeValues($product)),
            $description,
        ])), 0, 600);
    }

    /**
     * Documents with their text vector (when semantic search is on). If the embedding service
     * is down the documents are still indexed, keyword-only, and the nightly reindex adds the
     * vectors later.
     *
     * @param  iterable<Product> $products
     * @return array<int, array>
     */
    public function buildMany(iterable $products): array
    {
        $docs = $texts = [];
        foreach ($products as $p) {
            $docs[$p->id] = $this->build($p);
            $texts[$p->id] = $this->semanticText($p);
        }
        if (!$this->embeddings->semanticEnabled()) {
            return array_values($docs);
        }

        try {
            $vectors = $this->embeddings->documentVectors($texts);
        } catch (SearchUnavailable $e) {
            Log::warning('[Search] Indexing products without text vectors: ' . $e->getMessage());
            $vectors = [];
        }
        foreach ($docs as $id => &$doc) {
            $doc['_vectors'] = ['text' => $vectors[$id] ?? null];
        }
        return array_values($docs);
    }

    private function rawTranslations(Product $product): array
    {
        $raw = $product->getRawOriginal('translations');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    /** @return string[] category + subcategory names in every language */
    public function categoryNames(Product $product): array
    {
        $names = [];
        foreach ([$product->category, $product->subcategory] as $c) {
            if ($c) {
                foreach (['name', 'name_fr', 'name_ar'] as $col) {
                    $names[] = $c->getRawOriginal($col);
                }
            }
        }
        return array_unique(array_filter($names));
    }

    /** @return string[] attribute values (brand, material, gender…) and active variant options (colors, sizes) */
    public function attributeValues(Product $product): array
    {
        $values = [];
        foreach ($product->attributeValues as $pav) {
            foreach ((array) (json_decode((string) $pav->value, true) ?? $pav->value) as $v) {
                if (is_scalar($v) && trim((string) $v) !== '' && mb_strlen((string) $v) <= 60) {
                    $values[] = (string) $v;
                }
            }
        }
        foreach ($product->activeVariants as $variant) {
            foreach ($variant->attributeOptions as $opt) {
                array_push($values, (string) $opt->value, (string) $opt->value_fr, (string) $opt->value_ar);
            }
        }
        return array_values(array_unique(array_filter($values)));
    }
}
