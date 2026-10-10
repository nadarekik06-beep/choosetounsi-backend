<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Models\SellerOrderReminder;
use App\Services\Orders\WhatsApp\SellerReminderPayload;
use App\Services\Orders\WhatsApp\SellerReminderService;
use App\Services\Orders\WhatsApp\SellerWhatsAppNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin "WhatsApp reminders": sellers to notify about confirmed parcels.
 *
 *   GET  /api/admin/whatsapp-reminders                 overdue parcels + due reminders
 *   GET  /api/admin/whatsapp-reminders/count           sidebar badge
 *   POST /api/admin/seller-orders/{id}/whatsapp-sent   {attempt} the admin sent it (wa.me)
 */
class SellerWhatsAppController extends Controller
{
    private const WITH = [
        'order:id,order_number,confirmed_at',
        'seller:id,name,phone,whatsapp_number,preferred_language',
        'seller.sellerApplication',
        'items:id,seller_order_id,quantity',
        'reminders.sentBy:id,name',
    ];

    public function index(SellerReminderPayload $payload): JsonResponse
    {
        $overdue = SellerReminderService::overdue()->with(self::WITH)->orderBy('overdue_at')->get();

        // One line per parcel: its latest due notice (sending it supersedes the earlier ones)
        $due = SellerOrderReminder::where('status', SellerOrderReminder::DUE)
            ->whereHas('sellerOrder', fn ($q) => $q->where('status', 'confirmed')->whereNull('prepared_at'))
            ->with(array_map(fn ($r) => "sellerOrder.{$r}", self::WITH))
            ->orderBy('due_at')
            ->get()
            ->groupBy('seller_order_id')
            ->map(fn ($rows) => $rows->sortByDesc('attempt')->first())
            ->values();

        $frontend = (string) config('app.frontend_url');

        return response()->json(['success' => true, 'data' => [
            'overdue' => $overdue->map(fn (SellerOrder $so) => $payload->row($so))->values(),
            'due'     => $due->map(fn (SellerOrderReminder $r) => $payload->row($r->sellerOrder, $r))->values(),
            'counts'  => ['overdue' => $overdue->count(), 'due' => $due->count()],
            // Links in the messages point to FRONTEND_URL: warn when it isn't the live site
            'warnings' => preg_match('#//(localhost|127\.0\.0\.1)#', $frontend)
                ? ["FRONTEND_URL is {$frontend}: sellers can't open the order links. Set it to the live site in .env."]
                : [],
            'business_number' => config('seller_whatsapp.business_number'),
        ]]);
    }

    public function count(): JsonResponse
    {
        $due = SellerOrderReminder::where('status', SellerOrderReminder::DUE)
            ->whereHas('sellerOrder', fn ($q) => $q->where('status', 'confirmed')->whereNull('prepared_at'))
            ->distinct()->count('seller_order_id');
        $overdue = SellerReminderService::overdue()->count();

        return response()->json(['success' => true, 'data' => ['due' => $due, 'overdue' => $overdue, 'total' => $due + $overdue]]);
    }

    public function sent(Request $request, int $id, SellerWhatsAppNotifier $notifier, SellerReminderPayload $payload): JsonResponse
    {
        $data   = $request->validate(['attempt' => 'required|integer|in:0,1,2']);
        $parcel = SellerOrder::findOrFail($id);

        try {
            $notifier->send($parcel, (int) $data['attempt'], $request->user());
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        $parcel = SellerOrder::with(self::WITH)->findOrFail($id);
        return response()->json([
            'success' => true,
            'message' => 'WhatsApp notice recorded as sent.',
            'data'    => $payload->forParcel($parcel),
        ]);
    }
}
