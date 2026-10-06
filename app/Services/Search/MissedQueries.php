<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Zero/low-result searches, one row per normalized query per day (admin: "Missed searches";
 * seller: Growth Radar "hidden demand"). category_id = the category filter, or the category
 * of the best weak hit; null when nothing at all matched.
 */
class MissedQueries
{
    /** @param (callable(): ?int)|null $category resolved only when the search is actually logged */
    public function record(string $normalized, string $typed, int $results, ?callable $category = null): void
    {
        if (mb_strlen($normalized) < 2 || $results >= (int) config('search.low_results', 3)) {
            return;
        }
        try {
            $categoryId = $category ? $category() : null;
            DB::statement(
                'INSERT INTO search_missed_queries (query, day, searches, results, example, category_id, created_at, updated_at)
                 VALUES (?, ?, 1, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE searches = searches + 1, results = VALUES(results),
                     category_id = COALESCE(VALUES(category_id), category_id), updated_at = NOW()',
                [mb_substr($normalized, 0, 191), now()->toDateString(), $results, mb_substr(trim($typed), 0, 191), $categoryId]
            );
        } catch (Throwable $e) {
            Log::warning('[Search] Could not log missed query: ' . $e->getMessage());   // never break a search over a log line
        }
    }

    /** @return array<int, object> most-searched missed queries over the last $days days */
    public function top(int $days = 30, int $limit = 100, ?int $maxResults = null): array
    {
        return DB::table('search_missed_queries')
            ->where('day', '>=', now()->subDays($days)->toDateString())
            ->when($maxResults !== null, fn ($q) => $q->where('results', '<=', $maxResults))
            ->groupBy('query')
            ->selectRaw('query, SUM(searches) AS searches, MIN(results) AS min_results, MAX(results) AS max_results,
                         MAX(example) AS example, MIN(day) AS first_seen, MAX(day) AS last_seen')
            ->orderByDesc('searches')->orderByDesc('last_seen')
            ->limit($limit)->get()->all();
    }
}
