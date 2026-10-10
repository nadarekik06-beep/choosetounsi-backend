<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\Orders\ParcelStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One parcel's workflow, admin only (rules: App\Services\Orders\ParcelStatus):
 *   POST /api/admin/seller-orders/{id}/status              — any allowed move {status, note?}
 *   POST /api/admin/seller-orders/{id}/delivered           — delivered, cash collected by the courier
 *   POST /api/admin/seller-orders/{id}/refused             — refused by the client at the door
 *   POST /api/admin/seller-orders/{id}/returned-to-seller  — refused parcel back at the seller (stock back)
 *   GET  /api/admin/seller-orders/{id}/status-history      — every change: from → to, who, source, when
 */
class ParcelController extends Controller
{
    public function __construct(private ParcelStatus $parcels) {}

    public function status(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:' . implode(',', ParcelStatus::STATUSES),
            'note'   => 'nullable|string|max:255',
        ]);
        return $this->run(fn () => $this->parcels->transition(SellerOrder::findOrFail($id), $request->input('status'), [
            'by' => $request->user(), 'source' => 'admin', 'note' => $request->input('note'),
        ]), 'Parcel status updated.');
    }

    public function delivered(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => $this->parcels->markDelivered(SellerOrder::findOrFail($id), $request->user()), 'Parcel marked delivered: cash collected by the courier.');
    }

    public function refused(Request $request, int $id): JsonResponse
    {
        $request->validate(['note' => 'nullable|string|max:255']);
        return $this->run(fn () => $this->parcels->markRefused(SellerOrder::findOrFail($id), $request->user(), $request->input('note')), 'Parcel marked refused.');
    }

    public function returnedToSeller(Request $request, int $id): JsonResponse
    {
        $request->validate(['note' => 'nullable|string|max:255']);
        return $this->run(fn () => $this->parcels->markReturnedToSeller(SellerOrder::findOrFail($id), $request->user(), $request->input('note')), 'Parcel returned to the seller: stock released.');
    }

    public function history(int $id): JsonResponse
    {
        $parcel = SellerOrder::with('statusHistory.changedBy:id,name')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'status'                  => $parcel->status,
                'allowed_next'            => ParcelStatus::TRANSITIONS[$parcel->status] ?? [],
                'carrier_tracking_number' => $parcel->getAttribute('carrier_tracking_number'),
                'carrier_status_raw'      => $parcel->getAttribute('carrier_status_raw'),
                'history'                 => $parcel->statusHistory->map(fn ($h) => [
                    'from'               => $h->from_status,
                    'to'                 => $h->to_status,
                    'source'             => $h->source,
                    'changed_by'         => $h->changed_by ? ['id' => $h->changed_by, 'name' => $h->changedBy?->name] : null,
                    'carrier_status_raw' => $h->carrier_status_raw,
                    'note'               => $h->note,
                    'at'                 => $h->created_at,
                ])->values(),
            ],
        ]);
    }

    private function run(\Closure $action, string $message): JsonResponse
    {
        try {
            $parcel = $action();
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (InsufficientStock $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'message' => $message, 'data' => $parcel]);
    }
}
