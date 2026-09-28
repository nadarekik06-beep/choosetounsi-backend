<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Recommendation\HomeFeedBuilder;
use App\Services\Recommendation\InteractionTracker;
use App\Services\Recommendation\InterestProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/recommendations/debug?user_id=12            (or ?session_id=<guest uuid>)
 *     &refresh=1   rebuild the interest profile first
 *
 * Shows a viewer's interest profile in readable form and, for every product on
 * their homepage right now, which section it landed in and why.
 */
class RecommendationDebugController extends Controller
{
    public function __construct(
        private HomeFeedBuilder $builder,
        private InterestProfileService $profiles,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'    => 'required_without:session_id|nullable|integer|exists:users,id',
            'session_id' => 'required_without:user_id|nullable|string',
            'refresh'    => 'nullable|boolean',
        ]);
        $userId    = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $sessionId = $userId ? null : InteractionTracker::normalizeSessionId($data['session_id'] ?? null);
        if (!$userId && !$sessionId) {
            return response()->json(['success' => false, 'message' => 'session_id must be a guest UUID'], 422);
        }

        if ($request->boolean('refresh')) {
            $this->profiles->rebuild($userId, $sessionId);
        }
        $feed    = $this->builder->build($userId, $sessionId, explain: true);
        $profile = $feed['profile'];

        $names = $this->names($profile, $feed);

        return response()->json([
            'success' => true,
            'viewer'  => ['user_id' => $userId, 'session_id' => $sessionId],
            'profile' => [
                'state'         => $feed['profile_state'],
                'computed_at'   => date(DATE_ATOM, (int) $profile['computed_at']),
                'signal_count'  => $profile['signal_count'],
                'event_counts'  => $profile['event_counts'],
                'categories'    => $this->label($profile['categories'], $names['categories']),
                'subcategories' => $this->label($profile['subcategories'], $names['subcategories']),
                'sellers'       => $this->label($profile['sellers'], $names['sellers']),
                'brands'        => $profile['brands'],
                'price_range'   => $profile['price'],
                'followed_seller_ids' => $profile['followed_seller_ids'],
            ],
            'recent_signals'     => $this->recentSignals($userId, $sessionId),
            'excluded_purchased' => $feed['excluded_purchased'],
            'sections' => array_map(fn ($s) => [
                'key'      => $s['key'],
                'products' => array_map(fn ($p) => [
                    'id'        => $p['id'],
                    'name'      => $p['name'],
                    'placement' => $p['placement'],
                    'score'     => $feed['explain'][$p['id']]['score'] ?? null,
                    'reason'    => $this->sentence($feed['explain'][$p['id']] ?? [], $names),
                    'details'   => $feed['explain'][$p['id']]['why'] ?? null,
                ], $s['products']),
            ], $feed['sections']),
        ]);
    }

    /** One readable sentence per product, built from the builder's explain notes. */
    private function sentence(array $note, array $names): string
    {
        $why = $note['why'] ?? [];
        $first = $why[0] ?? null;

        if ($first === 'sponsored') {
            return 'Paid placement (active sponsorship matching this viewer).';
        }
        if (isset($why['affinity'])) {
            $parts = $why['affinity']['parts'];
            arsort($parts);
            $bits = [];
            foreach (array_slice(array_filter($parts), 0, 3, true) as $dim => $v) {
                $bits[] = sprintf('%s %.0f%%', str_replace('_', ' ', $dim), $v * 100);
            }
            $seen = !empty($why['already_seen']) ? ' Already seen, so ranked lower.' : '';
            return 'Matches the interest profile: ' . implode(', ', $bits) . sprintf('; popularity %.0f%%.', $why['popularity'] * 100) . $seen;
        }

        return match (true) {
            $first === 'exploration'        => 'Exploration slot: a new product outside the usual interests.',
            array_key_exists('similar_to', $why) => sprintf('Similar to product #%s (%s similarity %.2f).', $why['similar_to'], $why['source'], $why['similarity']),
            $first === 'trending_7d'        => sprintf('Trending: weighted score %.0f from the last 7 days.', $why['trend_score']),
            $first === 'popular_all_time'   => 'Popular overall (not enough 7-day activity to rank on trend).',
            $first === 'in_your_favourites' => 'In the viewer\'s favourites.',
            $first === 'recently_viewed'    => 'Recently viewed.',
            $first === 'followed_seller'    => 'New/popular from a followed seller: ' . ($names['sellers'][$why['seller_id']] ?? "#{$why['seller_id']}") . '.',
            $first === 'frequent_seller'    => 'From a seller bought from in 2+ orders: ' . ($names['sellers'][$why['seller_id']] ?? "#{$why['seller_id']}") . '.',
            $first === 'new_arrival'        => 'New arrival.',
            $first === 'top_rated'          => sprintf('Top rated (%.1f★ from %d reviews).', $why['rating']['avg'], $why['rating']['count']),
            $first === 'popular_in_category'=> 'Popular in this category.',
            default                         => 'n/a',
        };
    }

    private function names(array $profile, array $feed): array
    {
        $sellerIds = array_keys($profile['sellers']);
        foreach ($feed['explain'] as $note) {
            if (isset($note['why']['seller_id'])) {
                $sellerIds[] = $note['why']['seller_id'];
            }
        }
        return [
            'categories'    => DB::table('categories')->whereIn('id', array_keys($profile['categories']))->pluck('name', 'id')->all(),
            'subcategories' => DB::table('subcategories')->whereIn('id', array_keys($profile['subcategories']))->pluck('name', 'id')->all(),
            'sellers'       => DB::table('users')->whereIn('id', array_unique($sellerIds))->pluck('name', 'id')->all(),
        ];
    }

    private function label(array $scores, array $names): array
    {
        $out = [];
        foreach ($scores as $id => $score) {
            $out[] = ['id' => $id, 'name' => $names[$id] ?? null, 'score' => $score];
        }
        return $out;
    }

    private function recentSignals(?int $userId, ?string $sessionId): array
    {
        $q = DB::table('user_interactions as i')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->orderByDesc('i.created_at')
            ->limit(25)
            ->select('i.event_type', 'i.product_id', 'p.name as product', 'i.seller_id', 'i.category_id',
                     'i.source_section', 'i.search_query', 'i.order_id', 'i.created_at');
        $userId ? $q->where('i.user_id', $userId) : $q->where('i.session_id', $sessionId)->whereNull('i.user_id');
        return $q->get()->all();
    }
}
