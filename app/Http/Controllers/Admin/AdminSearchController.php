<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Search\FingerprintClient;
use App\Services\Search\FingerprintIndex;
use App\Services\Search\MissedQueries;
use Illuminate\Http\Request;

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

    /** GET /api/admin/search/health — is photo search up, and how much is indexed. */
    public function health(FingerprintClient $client, FingerprintIndex $photos)
    {
        $service = $client->health();
        $index = $photos->get();
        return response()->json([
            'success'        => true,
            'ai_service'     => $service !== null,
            'photos_indexed' => $index['count'],
            'categories'     => count($index['centroids']),
            'image_model'    => $service['image_model'] ?? null,   // what the AI service runs now
            'index_model'    => $index['model'],                   // what the fingerprints were made with
            'embedder'       => $service ? ['image_model' => $service['image_model'] ?? null] : null,
        ]);
    }
}
