<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Profile API Controller
 * Manage user profile
 */
class ProfileApiController extends Controller
{
    /**
     * Get user profile
     */
    public function show(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Update profile
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users')->ignore($user->id)
            ],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => __('messages.profile.updated'),
            'user' => $user,
        ]);
    }

    /**
     * Update password
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = $request->user();

        // Verify current password
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => __('messages.profile.wrong_current_password'),
                'errors' => [
                    'current_password' => ['Current password is incorrect.']
                ]
            ], 422);
        }

        // Update password
        $user->update([
            'password' => Hash::make($validated['password'])
        ]);

        return response()->json([
            'message' => __('messages.profile.password_updated'),
        ]);
    }

    /**
     * Request seller role
     */
    public function requestSellerRole(Request $request)
    {
        $user = $request->user();

        // Check if already a seller
        if ($user->isSeller()) {
            return response()->json([
                'message' => __('messages.profile.already_seller'),
            ], 400);
        }

        // Change role to seller
        $user->update([
            'role' => 'seller',
            'is_approved' => false,
        ]);

        return response()->json([
            'message' => __('messages.profile.seller_application_submitted'),
            'user' => $user,
        ]);
    }
}