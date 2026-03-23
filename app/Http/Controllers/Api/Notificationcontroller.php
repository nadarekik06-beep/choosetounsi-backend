<?php
// app/Http/Controllers/Api/NotificationController.php
// Used by the SELLER dashboard  (guard: sanctum / api)

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     */
    public function index(Request $request)
    {
        $user          = $request->user();
        $notifications = $user->notifications()
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $notifications->map(fn ($n) => [
                'id'         => $n->id,
                'data'       => $n->data,
                'is_read'    => ! is_null($n->read_at),
                'read_at'    => $n->read_at,
                'created_at' => $n->created_at,
            ]),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
            ],
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     * Lightweight — polled every 30 s
     */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'success' => true,
            'count'   => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * PATCH /api/notifications/{id}/read
     */
    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * PATCH /api/notifications/read-all
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}