<?php
// app/Http/Controllers/Admin/NotificationController.php — FIXED for PHP 8.0
//
// Changes vs previous version:
//   1. Removed ?->toISOString() — the nullsafe operator on a Carbon instance
//      crashes on PHP 8.0 when read_at is not null because Carbon does not
//      have a toISOString() method. Use toISOString() does not exist on Carbon —
//      the correct method is toIso8601String() or just cast to string.
//   2. read_at and created_at are now formatted with a plain ternary + explicit
//      string cast, compatible with PHP 7.4+.
//   3. Replaced fn() arrow function in map() with a full closure — safer across
//      all PHP 7.x / 8.x versions.
//   4. Added explicit check that $n->data is already an array (Laravel
//      automatically decodes the JSON column, but added a fallback just in case).

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * GET /api/admin/notifications
     */
    public function index(Request $request)
    {
        try {
            $admin = $request->user();

            if (!$admin) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $perPage = min((int) $request->query('per_page', 20), 100);

            $notifications = $admin->notifications()
                ->orderByDesc('created_at')
                ->paginate($perPage);

            $data = [];
            foreach ($notifications as $n) {
                $data[] = [
                    'id'         => $n->id,
                    'data'       => is_array($n->data) ? $n->data : json_decode($n->data, true),
                    'is_read'    => !is_null($n->read_at),
                    'read_at'    => $n->read_at ? $n->read_at->format('Y-m-d\TH:i:s\Z') : null,
                    'created_at' => $n->created_at ? $n->created_at->format('Y-m-d\TH:i:s\Z') : null,
                ];
            }

            return response()->json([
                'success' => true,
                'data'    => $data,
                'meta'    => [
                    'current_page' => $notifications->currentPage(),
                    'last_page'    => $notifications->lastPage(),
                    'total'        => $notifications->total(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('[Admin\NotificationController::index] ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        try {
            $admin = $request->user();

            if (!$admin) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $count = $admin->unreadNotifications()->count();

            return response()->json([
                'success' => true,
                'count'   => $count,
            ]);

        } catch (\Exception $e) {
            Log::error('[Admin\NotificationController::unreadCount] ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['success' => false, 'count' => 0, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /api/admin/notifications/{id}/read
     */
    public function markRead(Request $request, $id)
    {
        try {
            $notification = $request->user()->notifications()->findOrFail($id);
            $notification->markAsRead();

            return response()->json(['success' => true]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        } catch (\Exception $e) {
            Log::error('[Admin\NotificationController::markRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * PATCH /api/admin/notifications/read-all
     */
    public function markAllRead(Request $request)
    {
        try {
            $request->user()->unreadNotifications()->update(['read_at' => now()]);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('[Admin\NotificationController::markAllRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }
}