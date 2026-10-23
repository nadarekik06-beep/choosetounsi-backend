<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerProfileChange;
use App\Models\User;
use App\Services\Sellers\StoreProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin side of a seller's store profile (seller page "Profil de la boutique").
 *
 *   GET  /api/admin/sellers/{id}/store-profile                     current logo / description / categories + history
 *   POST /api/admin/sellers/{id}/store-profile/restore/{changeId}  "Restaurer l'ancien logo / l'ancienne description"
 *
 * The history merges these changes with the pickup address changes.
 */
class SellerStoreProfileController extends Controller
{
    public function __construct(private StoreProfileService $profiles) {}

    public function show(int $sellerId): JsonResponse
    {
        User::findOrFail($sellerId);
        return response()->json(['success' => true, 'data' => $this->card($sellerId)]);
    }

    public function restore(Request $request, int $sellerId, int $changeId): JsonResponse
    {
        $change = SellerProfileChange::where('user_id', $sellerId)->findOrFail($changeId);
        $this->profiles->restore($request->user(), $change);

        return response()->json([
            'success' => true,
            'message' => $change->field === SellerProfileChange::LOGO ? 'Old logo restored.' : 'Old description restored.',
            'data'    => $this->card($sellerId),
        ]);
    }

    private function card(int $sellerId): array
    {
        $app = StoreProfileService::application($sellerId);
        $categories = collect($this->profiles->platformCategories())->keyBy('name');

        $current = $this->profiles->present($app);
        $current['categories'] = array_map(fn ($name) => [
            'name'    => $name,
            'name_fr' => $categories[$name]['name_fr'] ?? null,
            'known'   => isset($categories[$name]),
        ], $current['business_categories']);

        return $current + [
            'has_application' => $app !== null,
            'history'         => $this->profiles->history($sellerId, 60),
            'category_labels' => $categories->map(fn ($c) => $c['name_fr'] ?: $c['name'])->all(),
        ];
    }
}
