<?php

// app/Http/Controllers/Admin/AdminNotificationController.php
// Class name MUST be AdminNotificationController to match api.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminNotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $admin = $request->user();
            if (!$admin) return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);

            $perPage = min((int) $request->query('per_page', 20), 100);
            $notifications = $admin->notifications()->orderByDesc('created_at')->paginate($perPage);

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
            Log::error('[AdminNotificationController::index] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Server error.'], 500);
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            $admin = $request->user();
            if (!$admin) return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);

            return response()->json([
                'success' => true,
                'count'   => $admin->unreadNotifications()->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[AdminNotificationController::unreadCount] ' . $e->getMessage());
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
            Log::error('[AdminNotificationController::markRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

    public function markAllRead(Request $request)
    {
        try {
            $request->user()->unreadNotifications()->update(['read_at' => now()]);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('[AdminNotificationController::markAllRead] ' . $e->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

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