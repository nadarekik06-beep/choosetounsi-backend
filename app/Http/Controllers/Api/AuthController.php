<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCodeMail;
use App\Mail\WelcomeUserMail;
use App\Models\User;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Password;
use App\Mail\PasswordResetMail;
class AuthController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    //  CONSTANTS
    // ─────────────────────────────────────────────────────────────────────

    /** Minutes before a pending registration expires. */
    private const PENDING_TTL_MINUTES = 15;

    /** Maximum wrong-code attempts before the code is invalidated. */
    private const MAX_ATTEMPTS = 5;

    // ─────────────────────────────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Shared user response shape — always includes avatar.
     */
    private function userResponse(User $user): array
    {
        return [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'role'                 => $user->role,
            'is_approved'          => (bool) $user->is_approved,
            'is_active'            => (bool) $user->is_active,
            'avatar'               => $user->avatar ?? null,
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
                ->map(fn($p) => [
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
     * Generate a secure 6-digit code, zero-padded.
     */
    private function generateVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Cache key for a pending (pre-DB) registration.
     */
    private function pendingCacheKey(string $email): string
    {
        return 'pending_reg:' . strtolower(trim($email));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  REGISTER  — stores data in Cache, NOT in the users table
    // ─────────────────────────────────────────────────────────────────────
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'role'     => 'sometimes|in:client,seller',
        ]);

        $email = strtolower(trim($validated['email']));
        $role  = $validated['role'] ?? 'client';

        // ── Guard: block re-registration while a pending attempt is active ──
        if (Cache::has($this->pendingCacheKey($email))) {
            return response()->json([
                'message'            => 'A verification code was already sent to this email. Please check your inbox or request a new code.',
                'needs_verification' => true,
                'email'              => $email,
            ], 409);
        }

        $code = $this->generateVerificationCode();

        // ── Store everything in cache — NO database write yet ──────────────
        Cache::put(
            $this->pendingCacheKey($email),
            [
                'name'         => $validated['name'],
                'email'        => $email,
                'password'     => Hash::make($validated['password']),
                'role'         => $role,
                'code'         => $code,
                'expires_at'   => now()->addMinutes(self::PENDING_TTL_MINUTES)->toISOString(),
                'attempts'     => 0,
            ],
            now()->addMinutes(self::PENDING_TTL_MINUTES)
        );

        // ── Send verification email ────────────────────────────────────────
        // We need a temporary object that VerificationCodeMail can use.
        // We pass a plain stdClass so the Mailable can access ->name and ->email
        // without touching the DB.
        $tempUser        = new \stdClass();
        $tempUser->name  = $validated['name'];
        $tempUser->email = $email;

        try {
            Mail::to($email)->send(new VerificationCodeMail($tempUser, $code));
        } catch (\Exception $e) {
            // If mail fails, remove the cache entry so the user can retry cleanly
            Cache::forget($this->pendingCacheKey($email));
            \Log::error('VerificationCodeMail failed (register): ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to send verification email. Please try again.',
            ], 500);
        }

        return response()->json([
            'message'            => 'Registration initiated. Please check your email for the 6-digit verification code.',
            'needs_verification' => true,
            'email'              => $email,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  VERIFY EMAIL  — creates the user in DB only after successful verify
    // ─────────────────────────────────────────────────────────────────────
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|string|size:6',
        ]);

        $email = strtolower(trim($request->email));

        // ── IP-based rate limit: 10 attempts per minute ────────────────────
        $ipKey = 'verify-email-ip:' . $request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            $seconds = RateLimiter::availableIn($ipKey);
            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} second(s).",
            ], 429);
        }
        RateLimiter::hit($ipKey, 60);

        // ── Case A: user already exists and is verified (e.g. double-tap) ──
        $existingUser = User::where('email', $email)->first();
        if ($existingUser && $existingUser->email_verified_at) {
            RateLimiter::clear($ipKey);
            $existingUser->tokens()->delete();
            $token = $existingUser->createToken('api-token')->plainTextToken;
            return response()->json([
                'message' => 'Email already verified.',
                'token'   => $token,
                'user'    => $this->userResponse($existingUser),
            ]);
        }

        // ── Case B: look up pending registration in cache ──────────────────
        $cacheKey = $this->pendingCacheKey($email);
        $pending  = Cache::get($cacheKey);

        if (!$pending) {
            return response()->json([
                'message'      => 'No pending registration found for this email. Please register again.',
                'needs_resend' => true,
            ], 404);
        }

        // ── Code expired? ──────────────────────────────────────────────────
        if (now()->isAfter($pending['expires_at'])) {
            Cache::forget($cacheKey);
            return response()->json([
                'message'      => 'Verification code has expired. Please register again.',
                'needs_resend' => true,
            ], 422);
        }

        // ── Too many wrong attempts — code invalidated ─────────────────────
        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($cacheKey);
            return response()->json([
                'message'      => 'Too many incorrect attempts. Please register again.',
                'needs_resend' => true,
            ], 422);
        }

        // ── Wrong code — increment attempt counter in cache ────────────────
        if ($pending['code'] !== $request->code) {
            $pending['attempts']++;

            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($cacheKey);
                return response()->json([
                    'message'      => 'Too many incorrect attempts. Please register again.',
                    'needs_resend' => true,
                ], 422);
            }

            // Update attempts in cache (keep same TTL relative to expires_at)
            $remainingSeconds = max(0, now()->diffInSeconds($pending['expires_at'], false));
            Cache::put($cacheKey, $pending, $remainingSeconds);

            $remaining = self::MAX_ATTEMPTS - $pending['attempts'];
            return response()->json([
                'message' => "Incorrect code. {$remaining} attempt(s) remaining.",
            ], 422);
        }

        // ── Code is correct ────────────────────────────────────────────────
        // NOW we create the user in the database for the first time
        $user = User::create([
            'name'             => $pending['name'],
            'email'            => $pending['email'],
            'password'         => $pending['password'],   // already hashed
            'role'             => $pending['role'],
            'is_active'        => true,
            'is_approved'      => $pending['role'] === 'client',
            'email_verified_at' => now(),
        ]);

        // ── Clean up cache — pending registration no longer needed ─────────
        Cache::forget($cacheKey);
        RateLimiter::clear($ipKey);

        // ── Send welcome email (with featured products mini-catalog) ───────
        try {
            Mail::to($user->email)->send(
                new WelcomeUserMail($user, $this->featuredProductsForEmail())
            );
        } catch (\Exception $e) {
            \Log::error('WelcomeUserMail failed for user #' . $user->id . ': ' . $e->getMessage());
            // Non-fatal — user is already created, just log it
        }

        // ── Issue Sanctum token ────────────────────────────────────────────
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully! Welcome to ChooseTounsi.',
            'token'   => $token,
            'user'    => $this->userResponse($user),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  RESEND VERIFICATION CODE
    // ─────────────────────────────────────────────────────────────────────
    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $email = strtolower(trim($request->email));

        // ── Per-email rate limit: 3 resends per hour ───────────────────────
        $emailKey = 'resend-verification:' . $email;
        if (RateLimiter::tooManyAttempts($emailKey, 3)) {
            $seconds = RateLimiter::availableIn($emailKey);
            $minutes = (int) ceil($seconds / 60);
            return response()->json([
                'message' => "Too many resend requests. Try again in {$minutes} minute(s).",
            ], 429);
        }

        // ── If user already exists and is verified, no need to resend ──────
        $existingUser = User::where('email', $email)->first();
        if ($existingUser && $existingUser->email_verified_at) {
            return response()->json([
                'message' => 'This email address is already verified.',
            ], 400);
        }

        // ── Load pending registration from cache ───────────────────────────
        $cacheKey = $this->pendingCacheKey($email);
        $pending  = Cache::get($cacheKey);

        if (!$pending) {
            // Don't leak whether email is registered — always consume a rate-limit hit
            RateLimiter::hit($emailKey, 3600);
            return response()->json([
                'message' => 'No pending registration found. Please register again.',
            ], 404);
        }

        // ── Generate fresh code, reset attempts, extend TTL ───────────────
        $code                  = $this->generateVerificationCode();
        $pending['code']       = $code;
        $pending['attempts']   = 0;
        $pending['expires_at'] = now()->addMinutes(self::PENDING_TTL_MINUTES)->toISOString();

        Cache::put($cacheKey, $pending, now()->addMinutes(self::PENDING_TTL_MINUTES));
        RateLimiter::hit($emailKey, 3600);

        // ── Resend email ───────────────────────────────────────────────────
        $tempUser        = new \stdClass();
        $tempUser->name  = $pending['name'];
        $tempUser->email = $email;

        try {
            Mail::to($email)->send(new VerificationCodeMail($tempUser, $code));
        } catch (\Exception $e) {
            \Log::error('VerificationCodeMail (resend) failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send the verification email. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'A new verification code has been sent to your email.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  LOGIN  — unchanged
    // ─────────────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────────
    //  GET CURRENT AUTHENTICATED USER  — unchanged
    // ─────────────────────────────────────────────────────────────────────
    public function user(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $user->load('sellerApplication');
        $application = $user->sellerApplication;

        return response()->json([
            'user' => [
                'id'                   => $user->id,
                'name'                 => $user->name,
                'email'                => $user->email,
                'role'                 => $user->role,
                'is_approved'          => $user->is_approved,
                'is_active'            => $user->is_active,
                'avatar'               => $user->avatar,
                'active_plan'          => $application?->plan ?? 'free',
                'onboarding_completed' => (bool) $user->onboarding_completed,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  LOGOUT  — unchanged
    // ─────────────────────────────────────────────────────────────────────
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  GOOGLE OAUTH  — unchanged
    // ─────────────────────────────────────────────────────────────────────
    public function googleRedirect()
    {
        $url = Socialite::driver('google')
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

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
                'google_id'         => $googleUser->getId(),
                'avatar'            => $googleUser->getAvatar(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if (!$user->is_active) {
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/auth/login?error=account_deactivated');
            }
        } else {
            $user = User::create([
                'name'              => $googleUser->getName(),
                'email'             => $googleUser->getEmail(),
                'google_id'         => $googleUser->getId(),
                'avatar'            => $googleUser->getAvatar(),
                'password'          => Hash::make(Str::random(32)),
                'role'              => 'client',
                'is_active'         => true,
                'is_approved'       => true,
                'email_verified_at' => now(),
            ]);
            $isNewUser = true;
        }

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
    // ─────────────────────────────────────────────────────────────────────
    //  FORGOT PASSWORD — sends a reset link to the user's email
    // ─────────────────────────────────────────────────────────────────────
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);
 
        // ── Rate limit: 3 requests per hour per email ──────────────────────
        $rateLimitKey = 'forgot-password:' . strtolower($request->email);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = (int) ceil($seconds / 60);
            return response()->json([
                'message' => "Too many requests. Please try again in {$minutes} minute(s).",
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 3600);
 
        $user = User::where('email', $request->email)->first();
 
        // ── Always return success to prevent email enumeration ─────────────
        // Even if the email doesn't exist, we return the same response.
        // This is a security best practice.
        if (!$user) {
            return response()->json([
                'message' => 'If this email is registered, you will receive a password reset link shortly.',
            ]);
        }
 
        // ── Block Google-only accounts (no password set) ───────────────────
        if ($user->google_id && !$user->password) {
            return response()->json([
                'message' => 'This account uses Google sign-in. Please continue with Google.',
            ], 400);
        }
 
        // ── Generate token via Laravel's Password broker ───────────────────
        // This stores a hashed token in the password_resets table automatically.
        $token = Password::broker()->createToken($user);
 
        // ── Send reset email ───────────────────────────────────────────────
        try {
            Mail::to($user->email)->send(new \App\Mail\PasswordResetMail($user, $token));
        } catch (\Exception $e) {
            \Log::error('PasswordResetMail failed for user #' . $user->id . ': ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send reset email. Please try again.',
            ], 500);
        }
 
        return response()->json([
            'message' => 'If this email is registered, you will receive a password reset link shortly.',
        ]);
    }
 
    // ─────────────────────────────────────────────────────────────────────
    //  RESET PASSWORD — validates token and updates the password
    // ─────────────────────────────────────────────────────────────────────
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
        ]);
 
        // ── Use Laravel's Password broker to validate token + reset ────────
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
 
                // Revoke all existing tokens — force re-login after reset
                $user->tokens()->delete();
            }
        );
 
        // ── Handle broker response ─────────────────────────────────────────
        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password reset successfully. You can now log in with your new password.',
            ]);
        }
 
        // Map Laravel broker status to human-readable errors
        $errors = [
            Password::INVALID_TOKEN => 'This reset link is invalid or has already been used.',
            Password::INVALID_USER  => 'No account found with this email address.',
            Password::RESET_THROTTLED => 'Too many reset attempts. Please wait before trying again.',
        ];
 
        return response()->json([
            'message' => $errors[$status] ?? 'Failed to reset password. Please request a new link.',
        ], 422);
    }
}