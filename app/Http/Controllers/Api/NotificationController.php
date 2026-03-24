<?php
// app/Http/Controllers/Api/NotificationController.php — FIXED for PHP 8.0
// Used by the SELLER dashboard (guard: sanctum / api)
//
// Fix: replaced ->toISOString() (does not exist on Carbon) with ->format()
// and replaced arrow function fn() in map() with foreach for safety.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $perPage       = min((int) $request->query('per_page', 20), 100);
            $notifications = $user->notifications()
                ->orderByDesc('created_at')
                ->paginate($perPage);

            $data = [];
            foreach ($notifications as $n) {
                $data[] = [
                    'id'         => $n->id,
                    'data'       => is_array($n->data) ? $n->data : json_decode($n->data, true),
                    'is_read'    => !is_null($n->read_at),
                    'read_at'    => $n->read_at    ? $n->read_at->format('Y-m-d\TH:i:s\Z')    : null,
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
            Log::error('[Api\NotificationController::index] ' . $e->getMessage() . ' on line ' . $e->getLine());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            return response()->json([
                'success' => true,
                'count'   => $user->unreadNotifications()->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('[Api\NotificationController::unreadCount] ' . $e->getMessage() . ' on line ' . $e->getLine());
            return response()->json(['success' => false, 'count' => 0, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /api/notifications/{id}/read
     */
    public function markRead(Request $request, string $id)
    {
        try {
            $notification = $request->user()->notifications()->findOrFail($id);
            $notification->markAsRead();

            return response()->json(['success' => true]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        } catch (\Exception $e) {
            Log::error('[Api\NotificationController::markRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * PATCH /api/notifications/read-all
     */
    public function markAllRead(Request $request)
    {
        try {
            $request->user()->unreadNotifications()->update(['read_at' => now()]);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('[Api\NotificationController::markAllRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }
}