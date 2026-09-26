<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
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
}
