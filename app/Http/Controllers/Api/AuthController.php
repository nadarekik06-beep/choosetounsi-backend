<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCodeMail;
use App\Mail\WelcomeUserMail;
use App\Models\User;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
            'avatar'      => $user->avatar ?? null,
            'onboarding_completed' => (bool) $user->onboarding_completed,
        ];
    }

    /**
     * Pull 4 random active products for the welcome email mini-catalog.
     */
    private function featuredProductsForEmail(): array
    {
        try {
            return Product::where('is_active', true)
                ->where('is_approved', true)
                ->inRandomOrder()
                ->limit(4)
                ->with(['primaryImage'])
                ->get(['id', 'name', 'slug', 'price'])
                ->map(fn ($p) => [
                    'name'  => $p->name,
                    'slug'  => $p->slug,
                    'price' => $p->price,
                    'image' => $p->primaryImage
                        ? asset('storage/' . $p->primaryImage->image_path)
                        : null,
                ])
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Generate a secure 6-digit code and return it as a zero-padded string.
     */
    private function generateVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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
        $code = $this->generateVerificationCode();

        $user = User::create([
            'name'                          => $validated['name'],
            'email'                         => $validated['email'],
            'password'                      => Hash::make($validated['password']),
            'role'                          => $role,
            'is_active'                     => true,
            'is_approved'                   => $role === 'client',
            // Verification fields — email_verified_at stays null until confirmed
            'email_verification_code'       => $code,
            'email_verification_expires_at' => now()->addMinutes(10),
            'email_verification_attempts'   => 0,
        ]);

        // Send ONLY the verification code email at registration
        try {
            Mail::to($user->email)->send(new VerificationCodeMail($user, $code));
        } catch (\Exception $e) {
            \Log::error('VerificationCodeMail failed for user #' . $user->id . ': ' . $e->getMessage());
        }

        // Do NOT issue a token yet — user must verify first
        return response()->json([
            'message'            => 'Registration successful. Please check your email for the verification code.',
            'needs_verification' => true,
            'email'              => $user->email,
        ], 201);
    }

    /* ──────────────────────────────────────────────────────── */
    /*  Verify Email                                            */
    /* ──────────────────────────────────────────────────────── */
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|string|size:6',
        ]);

        // ── IP-based rate limit: 10 attempts per minute ────────────────────
        $ipKey = 'verify-email-ip:' . $request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            $seconds = RateLimiter::availableIn($ipKey);
            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} second(s).",
            ], 429);
        }
        RateLimiter::hit($ipKey, 60);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'No account found with this email.'], 404);
        }

        // Already verified — issue token (idempotent, safe for double-tap)
        if ($user->email_verified_at) {
            RateLimiter::clear($ipKey);
            $user->tokens()->delete();
            $token = $user->createToken('api-token')->plainTextToken;
            return response()->json([
                'message' => 'Email already verified.',
                'token'   => $token,
                'user'    => $this->userResponse($user),
            ]);
        }

        // Code was invalidated (too many wrong attempts) — force resend
        if (!$user->email_verification_code) {
            return response()->json([
                'message'      => 'No active verification code. Please request a new one.',
                'needs_resend' => true,
            ], 422);
        }

        // Code expired
        if ($user->email_verification_expires_at < now()) {
            return response()->json([
                'message'      => 'Verification code has expired. Please request a new one.',
                'needs_resend' => true,
            ], 422);
        }

        // Wrong code — increment attempt counter
        if ($user->email_verification_code !== $request->code) {
            $user->increment('email_verification_attempts');

            // After 5 wrong attempts: wipe the code to force a resend
            if ($user->email_verification_attempts >= 5) {
                $user->update([
                    'email_verification_code'       => null,
                    'email_verification_expires_at' => null,
                ]);
                return response()->json([
                    'message'      => 'Too many incorrect attempts. Please request a new verification code.',
                    'needs_resend' => true,
                ], 422);
            }

            $remaining = 5 - $user->email_verification_attempts;
            return response()->json([
                'message' => "Incorrect code. {$remaining} attempt(s) remaining.",
            ], 422);
        }

        // ── Code is correct ────────────────────────────────────────────────
        $user->update([
            'email_verified_at'             => now(),
            'email_verification_code'       => null,
            'email_verification_expires_at' => null,
            'email_verification_attempts'   => 0,
        ]);

        // Clear IP rate limiter on success
        RateLimiter::clear($ipKey);

        // NOW send the welcome email (with featured products mini-catalog)
        try {
            Mail::to($user->email)->send(
                new WelcomeUserMail($user, $this->featuredProductsForEmail())
            );
        } catch (\Exception $e) {
            \Log::error('WelcomeUserMail failed for user #' . $user->id . ': ' . $e->getMessage());
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully! Welcome to ChooseTounsi.',
            'token'   => $token,
            'user'    => $this->userResponse($user),
        ]);
    }

    /* ──────────────────────────────────────────────────────── */
    /*  Resend Verification Code                                */
    /* ──────────────────────────────────────────────────────── */
    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // ── Per-email rate limit: 3 resends per hour ───────────────────────
        $emailKey = 'resend-verification:' . strtolower($request->email);
        if (RateLimiter::tooManyAttempts($emailKey, 3)) {
            $seconds = RateLimiter::availableIn($emailKey);
            $minutes = (int) ceil($seconds / 60);
            return response()->json([
                'message' => "Too many resend requests. Try again in {$minutes} minute(s).",
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        // Don't leak whether the email exists in the system
        if (!$user) {
            RateLimiter::hit($emailKey, 3600);
            return response()->json([
                'message' => 'If this email is registered, a new code has been sent.',
            ]);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'This email address is already verified.'], 400);
        }

        $code = $this->generateVerificationCode();

        $user->update([
            'email_verification_code'       => $code,
            'email_verification_expires_at' => now()->addMinutes(10),
            'email_verification_attempts'   => 0,
        ]);

        RateLimiter::hit($emailKey, 3600);

        try {
            Mail::to($user->email)->send(new VerificationCodeMail($user, $code));
        } catch (\Exception $e) {
            \Log::error('VerificationCodeMail (resend) failed for user #' . $user->id . ': ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send the verification email. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'A new verification code has been sent to your email.',
        ]);
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

        // Block unverified email/password accounts from logging in
        if (!$user->email_verified_at) {
            return response()->json([
                'message'            => 'Please verify your email address before logging in.',
                'needs_verification' => true,
                'email'              => $user->email,
            ], 403);
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
                'active_plan' => $application?->plan ?? 'free',
                'onboarding_completed' => (bool) $user->onboarding_completed,
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

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        $isNewUser = false;

        if ($user) {
            $user->update([
                'google_id'        => $googleUser->getId(),
                'avatar'           => $googleUser->getAvatar(),
                // Google guarantees email ownership — auto-verify if not already
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if (!$user->is_active) {
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/auth/login?error=account_deactivated');
            }
        } else {
            // Brand-new Google user — already verified by Google
            $user = User::create([
                'name'             => $googleUser->getName(),
                'email'            => $googleUser->getEmail(),
                'google_id'        => $googleUser->getId(),
                'avatar'           => $googleUser->getAvatar(),
                'password'         => Hash::make(Str::random(32)),
                'role'             => 'client',
                'is_active'        => true,
                'is_approved'      => true,
                'email_verified_at' => now(),  // Google-verified — no OTP needed
            ]);
            $isNewUser = true;
        }

        // Send welcome email only to brand-new Google users (already verified)
        if ($isNewUser) {
            try {
                Mail::to($user->email)->send(
                    new WelcomeUserMail($user, $this->featuredProductsForEmail())
                );
            } catch (\Exception $e) {
                \Log::error('Welcome email failed for Google user #' . $user->id . ': ' . $e->getMessage());
            }
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        $userData    = base64_encode(json_encode($this->userResponse($user)));
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        return redirect("{$frontendUrl}/auth/google/callback?token={$token}&user={$userData}");
    }
}