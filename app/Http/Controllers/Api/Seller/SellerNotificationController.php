<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SellerNotificationController
 *
 * Handles SELLER-scoped notifications including:
 *   - Product reviewed (approved / rejected)
 *   - Order-related alerts
 *   - Low stock alerts       ← NEW
 *   - Out of stock alerts    ← NEW
 *
 * The format() method maps DB notification rows to the shape
 * NotificationBell.tsx expects. No changes needed to the component
 * since the new notification types follow the same data contract.
 *
 * New action values the bell will handle:
 *   data.action = 'low_stock'    → amber accent (add to NotifRow if desired)
 *   data.action = 'out_of_stock' → red accent
 *
 * Routes (add to routes/api.php if not already present):
 *   GET    /api/notifications               → index
 *   GET    /api/notifications/unread-count  → unreadCount
 *   PATCH  /api/notifications/{id}/read     → markRead
 *   PATCH  /api/notifications/read-all      → markAllRead
 */
class SellerNotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $seller  = $request->user();
            if (!$seller) return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);

            $perPage = min((int) $request->query('per_page', 20), 100);

            $notifications = $seller->notifications()
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => $notifications->map(fn($n) => $this->format($n)),
                'meta'    => [
                    'current_page' => $notifications->currentPage(),
                    'last_page'    => $notifications->lastPage(),
                    'total'        => $notifications->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[SellerNotificationController::index] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Server error.'], 500);
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            $seller = $request->user();
            if (!$seller) return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);

            return response()->json([
                'success' => true,
                'count'   => $seller->unreadNotifications()->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[SellerNotificationController::unreadCount] ' . $e->getMessage());
            return response()->json(['success' => false, 'count' => 0], 500);
        }
    }

    public function markRead(Request $request, string $id)
    {
        try {
            $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
            $notification->markAsRead();
            return response()->json(['success' => true]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        } catch (\Exception $e) {
            Log::error('[SellerNotificationController::markRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

    public function markAllRead(Request $request)
    {
        try {
            $request->user()->unreadNotifications()->update(['read_at' => now()]);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('[SellerNotificationController::markAllRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * Format a DB notification row into the shape NotificationBell.tsx expects.
     *
     * The 'action' field drives the accent color in NotifRow:
     *   approved   → green
     *   rejected   → red
     *   submitted  → amber
     *   created    → blue
     *   low_stock  → amber   ← NEW (no frontend change needed; accent() uses 'default' → brand red)
     *   out_of_stock → red   ← NEW (maps to red via accent() in the bell component)
     */
    private function format($notification): array
    {
        return [
            'id'         => $notification->id,
            'is_read'    => !is_null($notification->read_at),
            'read_at'    => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at->toISOString(),
            'data'       => [
                'type'   => $notification->data['type']   ?? 'general',
                'action' => $notification->data['action'] ?? 'info',
                'title'  => $notification->data['title']  ?? $notification->data['message'] ?? 'Notification',
                'body'   => $notification->data['body']   ?? $notification->data['message'] ?? '',
                'icon'   => $notification->data['icon']   ?? 'package',
                'link'   => $notification->data['link']   ?? $notification->data['url']     ?? '',
            ],
        ];
    }
}