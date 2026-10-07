<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\AvatarRequest;
use App\Http\Requests\Profile\ChangeEmailRequest;
use App\Http\Requests\Profile\NotificationPreferencesRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The signed-in user's own profile. Every action works on $request->user()
 * only — there is no way to address another account from here.
 */
class ProfileApiController extends Controller
{
    private const AVATAR_SIZE      = 400;
    private const AVATAR_DIR       = 'avatars';
    private const EMAIL_CODE_TTL   = 15;   // minutes
    private const EMAIL_MAX_TRIES  = 5;

    /** GET /api/profile */
    public function show(Request $request)
    {
        return response()->json(['success' => true, 'data' => self::payload($request->user())]);
    }

    /** PUT|PATCH /api/profile — personal info (any subset of the fields). */
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty(['first_name', 'last_name']) && filled($user->first_name) && filled($user->last_name)) {
            $user->name = $user->first_name . ' ' . $user->last_name;
        }
        $user->save();

        return response()->json([
            'success' => true,
            'message' => __('messages.profile.updated'),
            'data'    => self::payload($user),
        ]);
    }

    /** PUT /api/profile/password — change it, or set a first one (Google accounts). */
    public function updatePassword(UpdatePasswordRequest $request)
    {
        $user = $request->user();
        $key  = 'profile-password:' . $user->id;

        if ($user->has_password) {
            if (RateLimiter::tooManyAttempts($key, 5)) {
                return $this->tooMany($key);
            }
            if (!Hash::check((string) $request->current_password, $user->password)) {
                RateLimiter::hit($key, 900);
                return response()->json([
                    'success' => false,
                    'message' => __('messages.profile.wrong_current_password'),
                    'errors'  => ['current_password' => [__('messages.profile.wrong_current_password')]],
                ], 422);
            }
        }
        RateLimiter::clear($key);

        $user->forceFill([
            'password'     => Hash::make($request->password),
            'has_password' => true,
        ])->save();

        // Sign out every other device; keep this session.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current && $current->id, fn($q) => $q->where('id', '!=', $current->id))->delete();

        app(\App\Services\Notifications\BuyerNotifier::class)->send($user, new \App\Notifications\Buyer\AccountSecurityNotification('password_changed'));

        return response()->json([
            'success' => true,
            'message' => __('messages.profile.password_updated'),
            'data'    => self::payload($user),
        ]);
    }

    /**
     * POST /api/profile/avatar — the upload is decoded and re-encoded as a
     * square JPEG with GD, which drops metadata and anything smuggled inside
     * the original file.
     */
    public function uploadAvatar(AvatarRequest $request)
    {
        $user  = $request->user();
        $image = @imagecreatefromstring((string) file_get_contents($request->file('avatar')->getRealPath()));
        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => __('messages.profile.avatar_unreadable'),
                'errors'  => ['avatar' => [__('messages.profile.avatar_unreadable')]],
            ], 422);
        }

        $w    = imagesx($image);
        $h    = imagesy($image);
        $side = min($w, $h);
        $out  = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // flatten PNG transparency
        imagecopyresampled($out, $image, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2),
            self::AVATAR_SIZE, self::AVATAR_SIZE, $side, $side);

        ob_start();
        imagejpeg($out, null, 86);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        imagedestroy($out);

        $path = self::AVATAR_DIR . '/' . $user->id . '/' . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, $jpeg);

        $this->deleteStoredAvatar($user);
        $user->forceFill(['avatar' => Storage::disk('public')->url($path)])->save();

        return response()->json([
            'success' => true,
            'message' => __('messages.profile.avatar_updated'),
            'data'    => self::payload($user),
        ]);
    }

    /** DELETE /api/profile/avatar — back to the initials avatar. */
    public function deleteAvatar(Request $request)
    {
        $user = $request->user();
        $this->deleteStoredAvatar($user);
        $user->forceFill(['avatar' => null])->save();

        return response()->json([
            'success' => true,
            'message' => __('messages.profile.avatar_removed'),
            'data'    => self::payload($user),
        ]);
    }

    /** POST /api/profile/email — step 1: send a code to the new address. */
    public function requestEmailChange(ChangeEmailRequest $request)
    {
        $user = $request->user();

        $pwKey = 'profile-password:' . $user->id;
        if ($user->has_password) {
            if (RateLimiter::tooManyAttempts($pwKey, 5)) {
                return $this->tooMany($pwKey);
            }
            if (!Hash::check((string) $request->current_password, $user->password)) {
                RateLimiter::hit($pwKey, 900);
                return response()->json([
                    'success' => false,
                    'message' => __('messages.profile.wrong_current_password'),
                    'errors'  => ['current_password' => [__('messages.profile.wrong_current_password')]],
                ], 422);
            }
        }

        if ($request->email === strtolower($user->email)) {
            return response()->json([
                'success' => false,
                'message' => __('messages.profile.email_same'),
                'errors'  => ['email' => [__('messages.profile.email_same')]],
            ], 422);
        }

        $sendKey = 'profile-email-send:' . $user->id;
        if (RateLimiter::tooManyAttempts($sendKey, 3)) {
            return $this->tooMany($sendKey);
        }
        RateLimiter::hit($sendKey, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->emailCacheKey($user), [
            'email'    => $request->email,
            'code'     => Hash::make($code),
            'attempts' => 0,
        ], now()->addMinutes(self::EMAIL_CODE_TTL));

        try {
            $to = (object) ['name' => $user->name, 'email' => $request->email];
            Mail::to($request->email)->send(new VerificationCodeMail($to, $code));
        } catch (\Throwable $e) {
            Cache::forget($this->emailCacheKey($user));
            Log::error('[Profile] email-change code failed for user #' . $user->id . ': ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => __('messages.auth.verification_send_failed')], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.profile.email_code_sent', ['email' => $request->email]),
            'data'    => ['email' => $request->email, 'expires_in' => self::EMAIL_CODE_TTL * 60],
        ]);
    }

    /** POST /api/profile/email/verify — step 2: code from the new inbox. */
    public function confirmEmailChange(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        $user    = $request->user();
        $key     = $this->emailCacheKey($user);
        $pending = Cache::get($key);

        if (!$pending) {
            return response()->json(['success' => false, 'message' => __('messages.profile.email_code_expired')], 422);
        }

        if (!Hash::check($request->code, $pending['code'])) {
            $pending['attempts']++;
            if ($pending['attempts'] >= self::EMAIL_MAX_TRIES) {
                Cache::forget($key);
                return response()->json(['success' => false, 'message' => __('messages.auth.too_many_incorrect')], 422);
            }
            Cache::put($key, $pending, now()->addMinutes(self::EMAIL_CODE_TTL));
            return response()->json([
                'success' => false,
                'message' => __('messages.auth.incorrect_code', ['remaining' => self::EMAIL_MAX_TRIES - $pending['attempts']]),
                'errors'  => ['code' => [__('messages.auth.incorrect_code', ['remaining' => self::EMAIL_MAX_TRIES - $pending['attempts']])]],
            ], 422);
        }

        // Someone may have registered that address in the meantime.
        if (User::where('email', $pending['email'])->whereKeyNot($user->id)->exists()) {
            Cache::forget($key);
            return response()->json(['success' => false, 'message' => __('validation.unique', ['attribute' => 'e-mail'])], 422);
        }

        Cache::forget($key);
        $previous = $user->email;
        $user->forceFill(['email' => $pending['email'], 'email_verified_at' => now()])->save();

        // Security: bell + e-mail to the new address, and an e-mail to the previous one.
        app(\App\Services\Notifications\BuyerNotifier::class)->send($user, new \App\Notifications\Buyer\AccountSecurityNotification('email_changed', ['email' => $user->email]));
        if (filled($previous) && $previous !== $user->email) {
            try {
                \Illuminate\Support\Facades\Notification::route('mail', $previous)
                    ->notify(new \App\Notifications\Buyer\AccountSecurityNotification('email_changed', ['email' => $user->email]));
            } catch (\Throwable $e) {
                Log::error('[Profile] email-change notice to the previous address failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.profile.email_updated'),
            'data'    => self::payload($user),
        ]);
    }

    /**
     * PUT /api/profile/notifications — per-category channel choices
     * (NotificationPreferences). Locked channels can't be sent; promotions_email
     * is the marketing e-mail consent (same as /api/account/marketing-consent).
     */
    public function updateNotifications(NotificationPreferencesRequest $request)
    {
        $user    = $request->user();
        $choices = array_map('boolval', $request->validated());

        if (array_key_exists('promotions_email', $choices)) {
            app(\App\Services\Ads\MarketingConsent::class)->set($user, $choices['promotions_email']);
            unset($choices['promotions_email']);
        }

        $user->notification_preferences = array_merge(
            array_fill_keys(NotificationPreferencesRequest::KEYS, true),
            $user->notification_preferences ?? [],
            $choices,
        );
        $user->save();

        return response()->json([
            'success' => true,
            'message' => __('messages.profile.preferences_saved'),
            'data'    => self::payload($user),
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The profile as the storefront sees it (also used by the overview). */
    public static function payload(User $user): array
    {
        // Older accounts only have `name`: offer it split as a starting point.
        $parts = preg_split('/\s+/u', trim((string) $user->name), 2);

        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'suggested_first_name' => $user->first_name ?? ($parts[0] ?? null),
            'suggested_last_name'  => $user->last_name ?? ($parts[1] ?? null),
            'email'             => $user->email,
            'email_verified'    => (bool) $user->email_verified_at,
            'phone'             => $user->phone,
            'date_of_birth'     => $user->date_of_birth?->format('Y-m-d'),
            'gender'            => $user->gender,
            'avatar'            => $user->avatar,
            'role'              => $user->role,
            'is_google'         => filled($user->google_id),
            'has_password'      => (bool) $user->has_password,
            'member_since'      => $user->created_at?->toISOString(),
            'notification_preferences' => array_merge(
                array_fill_keys(NotificationPreferencesRequest::KEYS, true),
                $user->notification_preferences ?? [],
            ),
            // One row per category: in_app / email → {enabled, locked, opt_in}
            'notification_settings' => \App\Notifications\Support\NotificationPreferences::settings($user),
            'marketing_emails_opt_in' => (bool) $user->marketing_emails_opt_in,
            'completion'        => $user->profileCompletion()->toArray(),
        ];
    }

    private function deleteStoredAvatar(User $user): void
    {
        $prefix = Storage::disk('public')->url(self::AVATAR_DIR . '/' . $user->id . '/');
        if ($user->avatar && str_starts_with($user->avatar, $prefix)) {
            Storage::disk('public')->delete(self::AVATAR_DIR . '/' . $user->id . '/' . basename($user->avatar));
        }
    }

    private function emailCacheKey(User $user): string
    {
        return 'profile-email-change:' . $user->id;
    }

    private function tooMany(string $key)
    {
        $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
        return response()->json([
            'success' => false,
            'message' => __('messages.auth.too_many_requests_minutes', ['minutes' => max(1, $minutes)]),
        ], 429);
    }
}
