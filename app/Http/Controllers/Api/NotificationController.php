<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\Support\Payload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The signed-in user's notifications (storefront bell, /notifications page,
 * seller dashboard bell).
 *
 *   ?audience=buyer|seller|admin   one bell's rows only (a seller who buys has both);
 *                                  applies to the list, the unread count and read-all
 *   ?category=orders|payments|…    list filter (/notifications tabs)
 *   ?unread=1                      unread only
 *
 * Without ?audience everything is returned, as before.
 */
class NotificationController extends Controller
{
    /** GET /api/notifications */
    public function index(Request $request)
    {
        try {
            $perPage       = min(max((int) $request->query('per_page', 20), 1), 100);
            $notifications = $this->scoped($request)
                ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
                ->when($request->filled('category'), fn ($q) => $q->where('category', (string) $request->query('category')))
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => $notifications->getCollection()->map(fn ($n) => $this->format($n))->values(),
                'meta'    => [
                    'current_page' => $notifications->currentPage(),
                    'last_page'    => $notifications->lastPage(),
                    'total'        => $notifications->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Api\NotificationController::index] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => __('messages.notification.load_failed')], 500);
        }
    }

    /** GET /api/notifications/unread-count — also per category, for the page tabs. */
    public function unreadCount(Request $request)
    {
        try {
            $byCategory = $this->scoped($request)
                ->whereNull('read_at')
                ->selectRaw('category, COUNT(*) as c')
                ->groupBy('category')
                ->pluck('c', 'category')
                ->map(fn ($c) => (int) $c);

            return response()->json([
                'success'     => true,
                'count'       => (int) $byCategory->sum(),
                'by_category' => $byCategory->filter(fn ($c, $k) => $k !== '' && $k !== null),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Api\NotificationController::unreadCount] ' . $e->getMessage());
            return response()->json(['success' => false, 'count' => 0], 500);
        }
    }

    /** PATCH /api/notifications/{id}/read */
    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();
        if (!$notification) {
            return response()->json(['success' => false, 'message' => __('messages.notification.not_found')], 404);
        }

        $notification->markAsRead();
        return response()->json(['success' => true]);
    }

    /** PATCH /api/notifications/read-all (?audience=, ?category=) */
    public function markAllRead(Request $request)
    {
        try {
            $updated = $this->scoped($request)
                ->whereNull('read_at')
                ->when($request->filled('category'), fn ($q) => $q->where('category', (string) $request->input('category')))
                ->update(['read_at' => now()]);

            return response()->json(['success' => true, 'updated' => $updated]);
        } catch (\Throwable $e) {
            Log::error('[Api\NotificationController::markAllRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

    /** DELETE /api/notifications/{id} */
    public function destroy(Request $request, string $id)
    {
        $deleted = $request->user()->notifications()->whereKey($id)->delete();
        if (!$deleted) {
            return response()->json(['success' => false, 'message' => __('messages.notification.not_found')], 404);
        }
        return response()->json(['success' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The user's notifications, limited to ?audience= when given. */
    private function scoped(Request $request)
    {
        $audience = $request->input('audience');
        $query    = $request->user()->notifications()->getQuery();

        if (in_array($audience, Payload::AUDIENCES, true)) {
            $query->where('audience', $audience);
        }

        return $query;
    }

    /** Stored row → API row. Rows not yet normalized are read through the contract too. */
    private function format($n): array
    {
        $raw  = is_array($n->data) ? $n->data : (json_decode((string) $n->data, true) ?: []);
        $data = Payload::isNormalized($raw) ? $raw : Payload::normalize($raw, $n->type, request()->user());

        return [
            'id'         => $n->id,
            'data'       => $data,
            'audience'   => $n->audience ?? $data['audience'],
            'category'   => $n->category ?? $data['category'],
            'is_read'    => $n->read_at !== null,
            'read_at'    => $n->read_at?->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $n->created_at?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
