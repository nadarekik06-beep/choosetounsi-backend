<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatLexicon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Chatbot → Unmatched queries.
 *
 * What customers asked the shopping assistant that ended with no product, relaxed words or words it did not
 * understand, most frequent first. Use it to decide which words to add to config/chatbot_darija.php.
 * Only the message text and what the bot understood are stored (no user or session).
 */
class AdminChatbotUnmatchedController extends Controller
{
    /** GET /admin/chatbot/unmatched?days=30&zero_only=1&limit=200 */
    public function index(Request $request, ChatLexicon $lex)
    {
        $days  = min(max((int) $request->query('days', 30), 1), 365);
        $limit = min(max((int) $request->query('limit', 200), 1), 500);

        $rows = DB::table('chatbot_unmatched_queries')
            ->where('created_at', '>=', now()->subDays($days))
            ->when($request->boolean('zero_only'), fn ($q) => $q->where('result_count', 0))
            ->get(['message', 'dialect', 'slots', 'result_count', 'created_at']);

        $grouped = $rows->groupBy(fn ($r) => $lex->norm($r->message))->map(function ($items, $key) {
            $last  = $items->sortByDesc('created_at')->first();
            $slots = json_decode($last->slots ?? '[]', true) ?: [];

            return [
                'query'        => $key,
                'example'      => $last->message,
                'count'        => $items->count(),
                'dialect'      => $last->dialect,
                'max_results'  => (int) $items->max('result_count'),
                'min_results'  => (int) $items->min('result_count'),
                'understood'   => array_filter([
                    'concept'  => $slots['concept'] ?? null,
                    'gender'   => $slots['gender'] ?? null,
                    'keywords' => $slots['keywords'] ?? [],
                    'relaxed'  => $slots['relaxed'] ?? [],
                ]),
                'first_seen'   => (string) $items->min('created_at'),
                'last_seen'    => (string) $items->max('created_at'),
            ];
        })->sortByDesc('count')->values()->take($limit);

        return response()->json([
            'success'        => true,
            'data'           => $grouped,
            'lexicon_file'   => 'config/chatbot_darija.php',
        ]);
    }

    /** DELETE /admin/chatbot/unmatched  { query }  — "handled": removes every row of that message */
    public function destroy(Request $request, ChatLexicon $lex)
    {
        $data = $request->validate(['query' => 'required|string|max:500']);

        $deleted = 0;
        foreach (DB::table('chatbot_unmatched_queries')->get(['id', 'message']) as $row) {
            if ($lex->norm($row->message) === $data['query']) {
                $deleted += DB::table('chatbot_unmatched_queries')->where('id', $row->id)->delete();
            }
        }

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }
}
