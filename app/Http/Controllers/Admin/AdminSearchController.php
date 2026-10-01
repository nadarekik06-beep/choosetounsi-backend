<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Search\MeiliClient;
use App\Services\Search\MissedQueries;
use App\Services\Search\SearchUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class AdminSearchController extends Controller
{
    /**
     * GET /api/admin/search/missed?days=30&zero_only=1&limit=100
     * Searches that found nothing (or fewer than search.low_results), most frequent first.
     */
    public function missed(Request $request, MissedQueries $missed)
    {
        $validated = $request->validate([
            'days'      => 'sometimes|integer|min:1|max:365',
            'limit'     => 'sometimes|integer|min:1|max:500',
            'zero_only' => 'sometimes|boolean',
        ]);

        $rows = $missed->top(
            $validated['days'] ?? 30,
            $validated['limit'] ?? 100,
            !empty($validated['zero_only']) ? 0 : null,
        );

        return response()->json([
            'success'       => true,
            'data'          => array_map(fn ($r) => [
                'query'       => $r->query,
                'example'     => $r->example,
                'searches'    => (int) $r->searches,
                'min_results' => (int) $r->min_results,
                'max_results' => (int) $r->max_results,
                'first_seen'  => $r->first_seen,
                'last_seen'   => $r->last_seen,
            ], $rows),
            'low_results'   => (int) config('search.low_results'),
            'synonyms_file' => 'choosetounsi-backend/resources/search/synonyms.txt',
        ]);
    }

    /** GET /api/admin/search/health — is each piece of search up, and how much is indexed. */
    public function health(MeiliClient $meili)
    {
        $stats = [];
        foreach (['products', 'images'] as $index) {
            try {
                $stats[$index] = $meili->stats($index)['numberOfDocuments'] ?? null;
            } catch (SearchUnavailable) {
                $stats[$index] = null;
            }
        }

        try {
            $embedder = Http::ai()->timeout(2)->get(rtrim(config('services.ai.url'), '/') . '/health')->json();
        } catch (Throwable) {
            $embedder = null;
        }

        return response()->json([
            'success'          => true,
            'meilisearch'      => $meili->healthy(),
            'products_indexed' => $stats['products'],
            'photos_indexed'   => $stats['images'],
            'embedder'         => $embedder ? ['image_model' => $embedder['image_model'] ?? null, 'text_model' => $embedder['text_model'] ?? null] : null,
            'semantic_search'  => (bool) config('search.semantic.enabled'),
        ]);
    }
}
