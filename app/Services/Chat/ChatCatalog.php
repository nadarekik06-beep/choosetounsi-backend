<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The real categories / subcategories, and the mapping from a lexicon concept
 * ("shoes") to real subcategory ids. The chatbot only ever filters on ids that
 * exist here — a concept never invents a category.
 */
class ChatCatalog
{
    public function __construct(private ChatLexicon $lex) {}

    /** @return array<int,object{id:int,slug:string,name:string,name_fr:?string,name_ar:?string,is_gendered:int}> */
    public function categories(): array
    {
        return Cache::remember('chat:catalog:categories', 600, fn () => DB::table('categories')
            ->where('is_active', true)->orderBy('order')
            ->get(['id', 'slug', 'name', 'name_fr', 'name_ar', 'is_gendered'])->all());
    }

    /** @return array<int,object{id:int,category_id:int,slug:string,name:string,name_fr:?string,name_ar:?string}> */
    public function subcategories(): array
    {
        return Cache::remember('chat:catalog:subcategories', 600, fn () => DB::table('subcategories')
            ->where('is_active', true)
            ->get(['id', 'category_id', 'slug', 'name', 'name_fr', 'name_ar'])->all());
    }

    public function category(?string $slug): ?object
    {
        foreach ($this->categories() as $c) {
            if ($c->slug === $slug) {
                return $c;
            }
        }
        return null;
    }

    public function categoryById(?int $id): ?object
    {
        foreach ($this->categories() as $c) {
            if ((int) $c->id === (int) $id) {
                return $c;
            }
        }
        return null;
    }

    public function conceptKeys(): array
    {
        return array_keys($this->lex->config('concepts', []));
    }

    public function concept(?string $key): ?array
    {
        return $key ? ($this->lex->config("concepts.{$key}") ?: null) : null;
    }

    /**
     * Real subcategory ids of a concept. Kids' subcategories are left out unless
     * a child recipient was detected (then only they and the grown-up ones both stay).
     *
     * @return int[]
     */
    public function subIds(string $conceptKey, bool $child = false): array
    {
        $c = $this->concept($conceptKey);
        if (!$c) {
            return [];
        }

        $kidsCategory = $this->category('kids-baby');
        $categoryId   = !empty($c['category']) ? ($this->category($c['category'])->id ?? null) : null;
        $ids = [];

        foreach ($this->subcategories() as $s) {
            if ($categoryId && (int) $s->category_id !== (int) $categoryId) {
                continue;
            }
            $hay = $this->lex->norm(implode(' | ', array_filter([$s->slug, $s->name, $s->name_fr])));

            if (!$this->matchesAny($hay, $c['subs'] ?? []) || $this->matchesAny($hay, $c['sub_exclude'] ?? [])) {
                continue;
            }

            $isKids = ($kidsCategory && (int) $s->category_id === (int) $kidsCategory->id)
                || $this->matchesAny($hay, $c['child_subs'] ?? ['\bkids?\b', 'enfant', 'baby', 'b[ée]b[ée]']);
            if ($isKids && !$child) {
                continue;
            }

            $ids[] = (int) $s->id;
        }

        return $ids;
    }

    private function matchesAny(string $hay, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (@preg_match('/' . str_replace('/', '\/', $p) . '/iu', $hay)) {
                return true;
            }
        }
        return false;
    }
}
