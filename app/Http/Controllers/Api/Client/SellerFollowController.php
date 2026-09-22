<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\SellerFollow;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerFollowController extends Controller
{
    /**
     * POST /api/seller-follows/{sellerId}
     * Toggle follow (follow if not following, unfollow if already following).
     */
    public function store(Request $request, int $sellerId): JsonResponse
    {
        $seller = User::approvedSellers()->where('id', $sellerId)->firstOrFail();
        $userId = $request->user()->id;

        $existing = SellerFollow::where('user_id', $userId)
            ->where('seller_id', $seller->id)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            SellerFollow::create(['user_id' => $userId, 'seller_id' => $seller->id]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'following'       => !$existing,
                'followers_count' => SellerFollow::where('seller_id', $seller->id)->count(),
            ],
        ]);
    }

    /**
     * GET /api/seller-follows/check/{sellerId}
     */
    public function check(Request $request, int $sellerId): JsonResponse
    {
        $following = SellerFollow::where('user_id', $request->user()->id)
            ->where('seller_id', $sellerId)
            ->exists();

        return response()->json(['success' => true, 'data' => ['following' => $following]]);
    }
}
