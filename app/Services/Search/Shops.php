<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Shops in the search bar: a query naming a seller's shop ("dar el moda", "techzone").
 *
 *   full    every word of the shop name typed  → the shop card, and its products count as
 *                                                best results (ProductSearch)
 *   whole   only some words, typed in full       → same, but only when no product matched the
 *           ("techzone", "bayti")                  words themselves ("home" stays a product search)
 *   partial the last word unfinished ("mod")     → the shop card / suggestion only
 *
 * Shop name = approved seller application's business name, else the account name; only
 * active sellers with at least one live product. Same tokens as the product index.
 */
class Shops
{
    const CACHE_KEY = 'search:shops:v1';
    const MAX = 3;

    public function __construct(private SearchText $text) {}

    /** @return array<int, array{id: int, name: string, avatar: ?string, products: int, full: bool, whole: bool}> best first */
    public function match(string $query, int $limit = self::MAX): array
    {
        $words = $this->text->words($query);
        if (!$words || mb_strlen(implode('', $words)) < 3) {
            return [];
        }
        $last = $this->text->stem(array_pop($words));
        $whole = array_map([$this->text, 'stem'], $words);

        $out = [];
        foreach ($this->all() as $shop) {
            $tokens = $shop['tokens'];
            foreach ($whole as $w) {
                if (!in_array($w, $tokens, true)) {
                    continue 2;
                }
            }
            // The word being typed: whole, or the start of a word of the name ("mod" → moda)
            $lastWhole = in_array($last, $tokens, true);
            if (!$lastWhole && !array_filter($tokens, fn ($t) => mb_strlen($last) >= 2 && str_starts_with($t, $last))) {
                continue;
            }
            $typed = array_unique([...$whole, ...($lastWhole ? [$last] : [])]);
            $full = $lastWhole && !array_diff($tokens, $typed);
            $out[] = ['id' => $shop['id'], 'name' => $shop['name'], 'avatar' => $shop['avatar'],
                      'products' => $shop['products'], 'full' => $full, 'whole' => $lastWhole];
        }
        usort($out, fn ($a, $b) => [$b['full'], $b['products']] <=> [$a['full'], $a['products']]);
        return array_slice($out, 0, $limit);
    }

    /** Live product ids of a shop. @return int[] */
    public function productIds(int $sellerId, ?int $categoryId = null): array
    {
        return DB::table('products')->where('seller_id', $sellerId)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->where('is_approved', 1)->where('is_active', 1)->whereNull('deleted_at')
            ->orderByDesc('views')->limit(200)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, array{id: int, name: string, avatar: ?string, products: int, tokens: string[]}> */
    private function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 600, function () {
            $rows = DB::table('users as u')
                ->join('products as p', 'p.seller_id', '=', 'u.id')
                ->where('u.is_active', 1)
                ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
                ->groupBy('u.id', 'u.name', 'u.avatar')
                ->selectRaw('u.id, u.name, u.avatar, COUNT(p.id) as products')
                ->get();
            $apps = DB::table('seller_applications')->whereIn('user_id', $rows->pluck('id'))
                ->where('status', 'approved')->orderBy('id')
                ->get(['user_id', 'business_name', 'profile_picture'])->keyBy('user_id');

            $shops = [];
            foreach ($rows as $r) {
                $app = $apps[$r->id] ?? null;
                $name = trim((string) ($app->business_name ?? '')) ?: (string) $r->name;
                $tokens = array_values(array_unique($this->text->tokens($name)));
                if (!$tokens) {
                    continue;
                }
                $shops[] = [
                    'id'       => (int) $r->id,
                    'name'     => $name,
                    'avatar'   => !empty($app->profile_picture) ? Storage::url($app->profile_picture) : ($r->avatar ?: null),
                    'products' => (int) $r->products,
                    'tokens'   => $tokens,
                ];
            }
            return $shops;
        });
    }
}
