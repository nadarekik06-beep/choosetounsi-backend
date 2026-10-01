<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Support\SellerPickup;
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
     * Where the courier collects this seller's parcels, plus which fields are
     * still missing (delivery slips can't be printed until it's complete).
     */
    public function pickupAddress(Request $request): JsonResponse
    {
        $seller = $request->user();
        $pickup = SellerPickup::for($seller);

        return response()->json(['success' => true, 'data' => [
            'full_name'          => $pickup['contact'],
            'phone_number'       => $pickup['phone'],
            'pickup_address'     => $pickup['address'],
            'city'               => $pickup['city'],
            'pickup_postal_code' => $pickup['postal_code'],
            'wilaya'             => $pickup['wilaya'],
            'pickup_notes'       => $pickup['notes'],
            'complete'           => $pickup['complete'],
            'missing'            => $pickup['missing'],
        ]]);
    }

    /**
     * PUT /api/seller/pickup-address
     *
     * Updates only the pickup fields of the seller's latest application —
     * no re-review, unlike SellerApplicationController::update().
     */
    public function updatePickupAddress(Request $request): JsonResponse
    {
        $application = SellerApplication::where('user_id', $request->user()->id)->latest()->first();

        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => __('seller.store.no_application'),
            ], 422);
        }

        SellerPickup::prepare($request);
        $application->update($request->validate(SellerPickup::rules()));

        return $this->pickupAddress($request);
    }
}
