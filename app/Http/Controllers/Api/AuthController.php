<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * Login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Wrong email or password.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Your account is deactivated.'], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'role'        => $user->role,
                'is_approved' => $user->is_approved,
                'is_active'   => $user->is_active,
            ],
        ]);
    }

    /**
     * Get current user
     */
    public function user(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Register
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:8|confirmed',
            'role'     => 'sometimes|in:client,seller',
        ]);

        $role = $validated['role'] ?? 'client';

        $user = User::create([
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'password'    => Hash::make($validated['password']),
            'role'        => $role,
            'is_active'   => true,
            'is_approved' => $role === 'client',
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Registered successfully',
            'token'   => $token,
            'user'    => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'role'        => $user->role,
                'is_approved' => $user->is_approved,
                'is_active'   => $user->is_active,
            ],
        ], 201);
    }

    // =========================================================================
    // GOOGLE OAUTH
    // =========================================================================

    /**
     * Step 1 — Return the Google OAuth URL to the frontend.
     * GET /api/auth/google/redirect
     */
    public function googleRedirect()
    {
        $url = Socialite::driver('google')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * Step 2 — Google redirects back here with a code.
     * GET /api/auth/google/callback
     * Creates/finds the user, issues a token, redirects to frontend.
     */
    public function googleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/auth/login?error=google_failed');
        }

        // Find by google_id first, then by email (user may have registered with email before)
        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user) {
            // Link google_id if missing
            if (!$user->google_id) {
                $user->update(['google_id' => $googleUser->getId()]);
            }
            if (!$user->is_active) {
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/auth/login?error=account_deactivated');
            }
        } else {
            // Brand new user via Google
            $user = User::create([
                'name'        => $googleUser->getName(),
                'email'       => $googleUser->getEmail(),
                'google_id'   => $googleUser->getId(),
                'password'    => Hash::make(Str::random(32)),
                'role'        => 'client',
                'is_active'   => true,
                'is_approved' => true,
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        // Pass token + user to frontend via query params (base64 encoded)
        $userData = base64_encode(json_encode([
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->role,
            'is_approved' => $user->is_approved,
            'is_active'   => $user->is_active,
        ]));

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        return redirect("{$frontendUrl}/auth/google/callback?token={$token}&user={$userData}");
    }
}