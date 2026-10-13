<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Models\SellerPickupAddress;
use App\Services\Sellers\PickupAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * SellerStoreProfileController
 *
 * Lets an approved seller manage their storefront branding (currently: cover
 * photo) WITHOUT going through SellerApplicationController::update(), which
 * blocks edits once approved and resets status to 'pending' on every change.
 * These endpoints only ever touch cosmetic fields on the seller's latest
 * approved application — never `status`.
 */
class SellerStoreProfileController extends Controller
{
    /**
     * GET /api/seller/store-profile
     */
    public function show(Request $request): JsonResponse
    {
        $seller  = $request->user();
        $branding = $seller->storefrontBranding();

        $application = SellerApplication::where('user_id', $seller->id)
            ->approved()
            ->latest()
            ->first();

        return response()->json(['success' => true, 'data' => [
            'business_name'         => $branding['business_name'],
            'business_description'  => $application?->business_description,
            'avatar'                => $branding['avatar'],
            'cover_photo'           => $branding['cover_photo'],
        ]]);
    }

    /**
     * POST /api/seller/store-profile/cover-photo
     */
    public function updateCoverPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'cover_photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $seller = $request->user();

        $application = SellerApplication::where('user_id', $seller->id)
            ->approved()
            ->latest()
            ->first();

        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => __('seller.store.no_application'),
            ], 422);
        }

        if ($application->cover_photo) {
            Storage::disk('public')->delete($application->cover_photo);
        }

        $path = $request->file('cover_photo')->store('seller-applications/covers', 'public');
        $application->update(['cover_photo' => $path]);

        return response()->json([
            'success' => true,
            'message' => __('seller.store.cover_updated'),
            'data'    => ['cover_photo' => Storage::url($path)],
        ]);
    }

    /**
     * GET /api/seller/pickup-address
     *
     * The pickup address (Intigo form fields), its status (verified by the
     * admin, created at Intigo) and what is still missing.
     */
    public function pickupAddress(Request $request, PickupAddressService $pickups): JsonResponse
    {
        $record = SellerPickupAddress::with('zone')->where('user_id', $request->user()->id)->first();

        return response()->json(['success' => true, 'data' => $pickups->present($record)]);
    }

    /**
     * PUT /api/seller/pickup-address
     *
     * Saves the address (no re-review of the application). Any change resets
     * the admin verification and the Intigo flag, is logged, and notifies the
     * admins: new parcels wait until the admin updated Intigo.
     */
    public function updatePickupAddress(Request $request, PickupAddressService $pickups): JsonResponse
    {
        $seller = $request->user();
        if (!SellerApplication::where('user_id', $seller->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => __('seller.store.no_application'),
            ], 422);
        }

        $result = $pickups->save($seller, PickupAddressService::validated($request), $seller, 'settings');
        $record = $result['record']->load('zone');

        return response()->json([
            'success' => true,
            'changed' => $result['changed'],
            'message' => $result['changed'] ? __('seller.pickup.pending_validation') : __('seller.pickup.unchanged'),
            'data'    => $pickups->present($record),
        ]);
    }
}
