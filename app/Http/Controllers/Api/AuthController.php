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
     * Shared user response shape — always includes avatar.
     */
    private function userResponse(User $user): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->role,
            'is_approved' => (bool) $user->is_approved,
            'is_active'   => (bool) $user->is_active,
            'avatar'      => $user->avatar ?? null,   // Google photo URL or null
        ];
    }

    /* ──────────────────────────────────────────────────────── */
    /*  Email / password login                                  */
    /* ──────────────────────────────────────────────────────── */
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
            'user'    => $this->userResponse($user),
        ]);
    }

    /* ──────────────────────────────────────────────────────── */
    /*  Get current authenticated user                          */
    /* ──────────────────────────────────────────────────────── */
public function user(Request $request): \Illuminate\Http\JsonResponse
{
    /** @var \App\Models\User $user */
    $user = $request->user();

    // Eager-load the latest application so we don't N+1 later
    $user->load('sellerApplication');

    $application = $user->sellerApplication;

    return response()->json([
        'user' => [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->role,
            'is_approved' => $user->is_approved,
            'is_active'   => $user->is_active,
            'avatar'      => $user->avatar,

            // ── THE FIX: surface active plan at the top level ────────────
            // Read from seller_applications.plan (your current source of truth)
            // 'free' if no application exists yet (safe default)
            'active_plan' => $application?->plan ?? 'free',
        ],
    ]);
}
    /* ──────────────────────────────────────────────────────── */
    /*  Logout                                                  */
    /* ──────────────────────────────────────────────────────── */
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    /* ──────────────────────────────────────────────────────── */
    /*  Register                                                */
    /* ──────────────────────────────────────────────────────── */
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
            'user'    => $this->userResponse($user),
        ], 201);
    }

    /* ──────────────────────────────────────────────────────── */
    /*  Google OAuth – Step 1: return redirect URL              */
    /* ──────────────────────────────────────────────────────── */
    public function googleRedirect()
    {
        $url = Socialite::driver('google')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /* ──────────────────────────────────────────────────────── */
    /*  Google OAuth – Step 2: handle callback                  */
    /* ──────────────────────────────────────────────────────── */
    public function googleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/auth/login?error=google_failed');
        }

        // Find by google_id first, then by email
        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user) {
            // Update google_id and avatar every login so photo stays fresh
            $user->update([
                'google_id' => $googleUser->getId(),
                'avatar'    => $googleUser->getAvatar(),   // always refresh
            ]);

            if (!$user->is_active) {
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/auth/login?error=account_deactivated');
            }
        } else {
            // Brand-new user via Google
            $user = User::create([
                'name'        => $googleUser->getName(),
                'email'       => $googleUser->getEmail(),
                'google_id'   => $googleUser->getId(),
                'avatar'      => $googleUser->getAvatar(),  // save Google photo
                'password'    => Hash::make(Str::random(32)),
                'role'        => 'client',
                'is_active'   => true,
                'is_approved' => true,
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        // Pass token + user to frontend via query params (base64 encoded)
        $userData    = base64_encode(json_encode($this->userResponse($user)));
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        return redirect("{$frontendUrl}/auth/google/callback?token={$token}&user={$userData}");
    }
}