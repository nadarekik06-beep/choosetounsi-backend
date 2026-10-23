<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Models\SellerPickupAddress;
use App\Services\Sellers\PickupAddressService;
use App\Services\Sellers\StoreProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * SellerStoreProfileController
 *
 * Lets an approved seller manage their storefront branding (cover photo, logo,
 * description, activity categories) WITHOUT going through
 * SellerApplicationController::update(), which
 * blocks edits once approved and resets status to 'pending' on every change.
 * These endpoints only ever touch cosmetic fields on the seller's latest
 * approved application — never `status`.
 */
class SellerStoreProfileController extends Controller
{
    /**
     * GET /api/seller/store-profile
     */
    public function show(Request $request, StoreProfileService $profiles): JsonResponse
    {
        $seller  = $request->user();
        $branding = $seller->storefrontBranding();

        $application = StoreProfileService::application($seller->id);

        return response()->json(['success' => true, 'data' => [
            'business_name'         => $branding['business_name'],
            'business_description'  => $application?->business_description,
            'business_categories'   => $profiles->present($application)['business_categories'],
            'avatar'                => $branding['avatar'],
            'cover_photo'           => $branding['cover_photo'],
            'can_edit'              => $application !== null && $seller->can('updateStoreProfile', $application),
            'limits'                => [
                'max_categories'  => StoreProfileService::MAX_CATEGORIES,
                'max_description' => StoreProfileService::MAX_DESCRIPTION,
                'max_logo_kb'     => StoreProfileService::MAX_LOGO_KB,
            ],
        ]]);
    }

    /** The seller's approved application, after the policy check (owner, active, approved). */
    private function editableApplication(Request $request)
    {
        $application = StoreProfileService::application($request->user()->id);
        if (!$application) {
            abort(response()->json(['success' => false, 'message' => __('seller.store.no_application')], 422));
        }
        if ($request->user()->cannot('updateStoreProfile', $application)) {
            abort(response()->json(['success' => false, 'message' => __('seller.store.forbidden')], 403));
        }
        return $application;
    }

    /**
     * POST /api/seller/store-profile/logo
     * JPG / PNG / WebP up to 4 MB. Cropped square, resized and re-encoded
     * server-side (metadata dropped). Live right away; the old one is kept.
     */
    public function updateLogo(Request $request, StoreProfileService $profiles): JsonResponse
    {
        $application = $this->editableApplication($request);
        $request->validate([
            'logo' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:' . StoreProfileService::MAX_LOGO_KB,
        ], [], ['logo' => __('seller.store.logo_attribute')]);

        $profiles->updateLogo($request->user(), $application, $request->file('logo'));
        $application->refresh();

        return response()->json([
            'success' => true,
            'message' => __('seller.store.logo_updated'),
            'data'    => $profiles->present($application),
        ]);
    }

    /**
     * PUT /api/seller/store-profile/description
     * Plain text, line breaks kept, 2000 characters max.
     */
    public function updateDescription(Request $request, StoreProfileService $profiles): JsonResponse
    {
        $application = $this->editableApplication($request);
        $request->validate([
            'business_description' => 'present|nullable|string|max:' . (StoreProfileService::MAX_DESCRIPTION * 2),
        ], [], ['business_description' => __('seller.store.description_attribute')]);

        $change = $profiles->updateDescription($request->user(), $application, $request->input('business_description'));
        $application->refresh();

        return response()->json([
            'success' => true,
            'changed' => $change !== null,
            'message' => $change ? __('seller.store.description_updated') : __('seller.store.unchanged'),
            'data'    => $profiles->present($application),
        ]);
    }

    /**
     * PUT /api/seller/store-profile/categories
     * The seller's activity categories, chosen from the platform list (max 5).
     * Information only: products can still use any platform category.
     */
    public function updateCategories(Request $request, StoreProfileService $profiles): JsonResponse
    {
        $application = $this->editableApplication($request);
        $request->validate([
            'categories'   => 'present|array|max:' . (StoreProfileService::MAX_CATEGORIES * 4),
            'categories.*' => 'string|max:255',
        ], [], ['categories' => __('seller.store.categories_attribute')]);

        $change = $profiles->updateCategories($request->user(), $application, $request->input('categories', []));
        $application->refresh();

        return response()->json([
            'success' => true,
            'changed' => $change !== null,
            'message' => $change ? __('seller.store.categories_updated') : __('seller.store.unchanged'),
            'data'    => $profiles->present($application),
        ]);
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
