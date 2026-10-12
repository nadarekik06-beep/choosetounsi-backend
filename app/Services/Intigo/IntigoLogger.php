<?php

namespace App\Services\Intigo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * intigo_logs writer: one row per request (endpoint, HTTP status, payload,
 * answer, timing, seller_order). The API key never reaches the table.
 */
class IntigoLogger
{
    private const SECRET_KEYS = ['x-api-key', 'api_key', 'apikey', 'authorization', 'secret', 'webhook_secret'];

    public function log(string $mode, string $method, string $endpoint, mixed $request, ?int $status, mixed $response, ?int $durationMs, ?int $sellerOrderId, ?string $error = null): void
    {
        try {
            DB::table('intigo_logs')->insert([
                'seller_order_id' => $sellerOrderId,
                'mode'            => $mode,
                'method'          => strtoupper($method),
                'endpoint'        => mb_substr($endpoint, 0, 191),
                'http_status'     => $status,
                'duration_ms'     => $durationMs,
                'request'         => $request === null ? null : $this->json($request),
                'response'        => $response === null ? null : $this->json($response),
                'error'           => $error !== null ? mb_substr($this->redactString($error), 0, 500) : null,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // A log failure must never break a send (and the seller_order may be gone)
            Log::warning('[Intigo] log write failed: ' . $e->getMessage());
        }
    }

    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = is_string($k) && in_array(strtolower($k), self::SECRET_KEYS, true) ? '[redacted]' : $this->redact($v);
            }
            return $out;
        }
        return is_string($value) ? $this->redactString($value) : $value;
    }

    private function redactString(string $value): string
    {
        foreach ([config('intigo.api_key'), config('intigo.webhook_secret')] as $secret) {
            if (is_string($secret) && strlen($secret) >= 6) {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }
        return $value;
    }

    private function json(mixed $value): string
    {
        $value = $this->redact($value);
        $json  = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        // Huge answers (parcel lists) are cut: the log is for diagnosis, not storage
        if (strlen($json) > 60000) {
            $json = json_encode(['truncated' => true, 'excerpt' => mb_substr($json, 0, 60000)], JSON_UNESCAPED_UNICODE);
        }
        return $json;
    }
}
