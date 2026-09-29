<?php

namespace App\Services\Ads;

/**
 * Signed ad tokens. Every served ad carries one; impression and click calls
 * must present it, so nobody can bill a campaign by posting random ids.
 *
 *   payload: c campaign id · p product id · pl placement · u user id · s guest session
 *            r request id · cpc price of a click (decided at auction time) · t issued · x expires
 *   token:   base64url(json) . "." . base64url(hmac_sha256(json, APP_KEY))
 */
class AdTokenService
{
    public function issue(array $payload, ?int $ttlSeconds = null): string
    {
        $now = time();
        $payload += ['t' => $now];
        $payload['x'] = $now + ($ttlSeconds ?? (int) config('ads.token_ttl_hours', 24) * 3600);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return $this->b64($json) . '.' . $this->b64($this->sign($json));
    }

    /** Token for e-mail links (longer lifetime). */
    public function issueForEmail(array $payload): string
    {
        return $this->issue($payload, (int) config('ads.email_token_ttl_days', 7) * 86400);
    }

    /** The payload of a valid, unexpired token, or null. */
    public function verify(?string $token): ?array
    {
        if (!is_string($token) || substr_count($token, '.') !== 1 || strlen($token) > 2048) {
            return null;
        }
        [$body, $sig] = explode('.', $token);
        $json = $this->unb64($body);
        $mac  = $this->unb64($sig);
        if ($json === null || $mac === null || !hash_equals($this->sign($json), $mac)) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['c'], $payload['pl'], $payload['r'], $payload['x'])) {
            return null;
        }
        return (int) $payload['x'] >= time() ? $payload : null;
    }

    private function sign(string $json): string
    {
        return hash_hmac('sha256', $json, $this->key(), true);
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $s): ?string
    {
        $raw = base64_decode(strtr($s, '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }
}
