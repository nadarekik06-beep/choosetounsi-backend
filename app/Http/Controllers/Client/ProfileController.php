<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Profile Controller
 * Allows clients (and sellers) to manage their profile
 */
class ProfileController extends Controller
{
    /**
     * Show profile page
     */
    public function index()
    {
        $user = auth()->user();
        return view('client.profile.index', compact('user'));
    }

    /**
     * Update profile information
     */
    public function update(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users')->ignore($user->id)
            ],
        ]);

        $user->update($validated);

        return back()->with('success', 'Profile updated successfully.');
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

        $user = auth()->user();

        // Verify current password
        if (!Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        // Update password
        $user->update([
            'password' => Hash::make($validated['password'])
        ]);

        return back()->with('success', 'Password updated successfully.');
    }

    /**
     * Request seller role
     * Allows clients to become sellers
     */
    public function requestSellerRole(Request $request)
    {
        $user = auth()->user();

        // Check if already a seller
        if ($user->isSeller()) {
            return back()->with('info', 'You are already a seller.');
        }

        // Change role to seller (not approved yet)
        $user->update([
            'role' => 'seller',
            'is_approved' => false,
        ]);

        return redirect()->route('seller.pending')
            ->with('success', 'Your seller application has been submitted. Awaiting admin approval.');
    }
}