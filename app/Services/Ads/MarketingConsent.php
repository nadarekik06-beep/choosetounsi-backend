<?php

namespace App\Services\Ads;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Marketing e-mail consent (opt-in, off by default). Every marketing e-mail
 * carries a one-click unsubscribe link built from the user's unsubscribe_token.
 */
class MarketingConsent
{
    public function set(User $user, bool $optIn): User
    {
        $user->forceFill([
            'marketing_emails_opt_in' => $optIn,
            'marketing_opt_in_at'     => $optIn ? now() : $user->marketing_opt_in_at,
            'unsubscribe_token'       => $user->unsubscribe_token ?: Str::random(40),
        ])->save();
        return $user;
    }

    /** Opt out by token (unsubscribe link). Returns the user, or null for an unknown token. */
    public function unsubscribe(string $token): ?User
    {
        if (strlen($token) !== 40) {
            return null;
        }
        $user = User::where('unsubscribe_token', $token)->first();
        return $user ? $this->set($user, false) : null;
    }

    /** Whether a token belongs to someone (read-only, for the confirmation page). */
    public function tokenExists(string $token): bool
    {
        return strlen($token) === 40 && User::where('unsubscribe_token', $token)->exists();
    }

    /**
     * Link in the e-mail body: the storefront confirmation page. Opening it
     * changes nothing (mail scanners open links); its button POSTs to the API.
     */
    public function unsubscribeUrl(User $user): string
    {
        return frontend_url('/unsubscribe/' . $this->token($user));
    }

    /**
     * List-Unsubscribe header target (RFC 8058): mail clients POST
     * "List-Unsubscribe=One-Click" to it. A GET only redirects to the page.
     */
    public function oneClickUrl(User $user): string
    {
        return url('/unsubscribe/' . $this->token($user));
    }

    private function token(User $user): string
    {
        if (!$user->unsubscribe_token) {
            $this->set($user, (bool) $user->marketing_emails_opt_in);
        }
        return $user->unsubscribe_token;
    }

    /** Users who may receive a marketing e-mail now (consent, active, verified, not mailed recently). */
    public function eligibleQuery(int $gapDays)
    {
        return User::query()
            ->where('marketing_emails_opt_in', true)
            ->where('is_active', true)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('email')
            ->where(fn ($q) => $q->whereNull('last_marketing_email_at')->orWhere('last_marketing_email_at', '<=', now()->subDays($gapDays)));
    }
}
