<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerPickupAddress;
use App\Models\User;
use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\IntigoException;
use App\Services\Sellers\PickupAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin side of a seller's pickup address (seller page "Adresse de pickup" card).
 *
 *   GET  /api/admin/sellers/{id}/pickup                card (Intigo form order), status, history, pending pickups
 *   PUT  /api/admin/sellers/{id}/pickup                admin edit (counts as verified; Intigo must be updated)
 *   POST /api/admin/sellers/{id}/pickup/verify         {verified: bool} "Adresse vérifiée"
 *   POST /api/admin/sellers/{id}/pickup/intigo-create  "Créer dans Intigo" (verified first)
 *   POST /api/admin/sellers/{id}/pickup/intigo-manual  {index, label?} "Pickup créé dans Intigo" (typed by hand)
 *
 * {id} is the user id: applicants (not sellers yet) work too.
 */
class SellerPickupController extends Controller
{
    public function __construct(private PickupAddressService $pickups) {}

    public function show(int $sellerId, IntigoClient $intigo): JsonResponse
    {
        $seller = User::findOrFail($sellerId);
        return response()->json(['success' => true, 'data' => $this->card($seller) + ['intigo_mode' => $intigo->mode()]]);
    }

    public function update(Request $request, int $sellerId): JsonResponse
    {
        $seller = User::findOrFail($sellerId);
        $this->pickups->save($seller, PickupAddressService::validated($request), $request->user(), 'admin');
        return $this->ok($seller, 'Pickup address saved. Update it in Intigo.');
    }

    public function verify(Request $request, int $sellerId): JsonResponse
    {
        $data   = $request->validate(['verified' => 'sometimes|boolean']);
        $seller = User::findOrFail($sellerId);
        $record = SellerPickupAddress::where('user_id', $seller->id)->firstOrFail();
        $this->pickups->verify($record, $request->user(), $data['verified'] ?? true);
        return $this->ok($seller, ($data['verified'] ?? true) ? 'Address marked as verified.' : 'Verification removed.');
    }

    public function createInIntigo(Request $request, int $sellerId, IntigoClient $intigo): JsonResponse
    {
        $seller = User::findOrFail($sellerId);
        try {
            $this->pickups->createInIntigo($seller, $request->user());
        } catch (IntigoException $e) {
            return response()->json(['success' => false, 'message' => 'Intigo: ' . $e->getMessage()], 502);
        }
        $index = $seller->fresh()->intigo_pickup_index;
        $check = $this->pickups->lastReadback;
        $message = $intigo->mode() === 'fake'
            ? "Simulated (fake mode): nothing was created at Intigo. Pickup index {$index} saved."
            : "Pickup address saved at Intigo (index {$index})."
              . ($check === [] ? ' Read back from Intigo: address, délégation and GPS match (Intigo does not return the coordinates: check the pin in its dashboard if in doubt).' : '')
              . ($check ? ' WARNING, Intigo differs: ' . implode('; ', $check) . '. The admins were alerted.' : '');
        return response()->json([
            'success'  => true,
            'message'  => $message,
            'readback' => $check,
            'data'     => $this->card($seller->fresh()),
        ]);
    }

    public function markCreated(Request $request, int $sellerId): JsonResponse
    {
        $data   = $request->validate([
            'index' => 'required|integer|min:0|max:100000',
            'label' => 'nullable|string|max:500',
        ]);
        $seller = User::findOrFail($sellerId);
        $this->pickups->markCreatedManually($seller, $request->user(), (int) $data['index'], $data['label'] ?? null);
        return $this->ok($seller, 'Marked as created in Intigo (index ' . $data['index'] . ').');
    }

    private function card(User $seller): array
    {
        $record = SellerPickupAddress::with('zone')->where('user_id', $seller->id)->first();
        return [
            'seller'  => ['id' => $seller->id, 'name' => $seller->name, 'email' => $seller->email, 'shop' => $seller->sellerApplication?->business_name],
            'pickup'  => $this->pickups->present($record, true),
            'history' => $this->pickups->history($seller->id),
        ];
    }

    private function ok(User $seller, string $message): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $this->card($seller->fresh())]);
    }
}
