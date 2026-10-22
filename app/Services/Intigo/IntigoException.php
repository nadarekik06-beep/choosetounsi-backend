<?php

namespace App\Services\Intigo;

/**
 * A failed Intigo call, with a message an admin can act on.
 *
 * retryable: no answer, timeout, connection error, 429, 5xx / 503. The parcel may
 * or may not exist at Intigo (ambiguous): look it up by cid before any retry.
 * Never retryable: 400 validation, 401 bad key, 402 balance, 404, 409 conflict.
 */
class IntigoException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly string $kind = 'error',
        public readonly mixed $body = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0);
    }

    public static function fromResponse(int $status, mixed $body): self
    {
        $detail = self::readable($body);
        [$kind, $prefix, $retryable] = match (true) {
            $status === 400, $status === 422 => ['validation', 'Rejected by Intigo', false],
            $status === 401, $status === 403 => ['auth', 'Intigo refused the API key (check INTIGO_API_KEY)', false],
            $status === 402 => ['balance', 'Intigo: insufficient account balance', false],
            $status === 404 => ['not_found', 'Not found at Intigo', false],
            $status === 409 => ['conflict', 'Intigo: status conflict', false],
            $status === 429 => ['rate_limited', 'Intigo: too many requests', true],
            $status === 503 => ['unavailable', 'Intigo is temporarily unavailable', true],
            $status >= 500  => ['server', 'Intigo server error', true],
            default         => ['error', "Unexpected Intigo answer (HTTP {$status})", false],
        };

        return new self($detail !== '' ? "{$prefix}: {$detail}" : $prefix, $status, $retryable, $kind, $body);
    }

    public static function connection(string $reason): self
    {
        return new self('No answer from Intigo (' . mb_substr($reason, 0, 200) . ')', null, true, 'connection');
    }

    /**
     * Intigo's error detail as one line: "phone1: at least 8 digits; city_name: Ville inconnue".
     * detail is an array of {loc, msg, type} or a string.
     */
    public static function readable(mixed $body): string
    {
        if (!is_array($body)) {
            return is_string($body) ? mb_substr(trim(strip_tags($body)), 0, 300) : '';
        }
        $detail = $body['detail'] ?? $body['message'] ?? $body['error'] ?? $body['errors'] ?? null;
        if (is_string($detail)) {
            return mb_substr($detail, 0, 300);
        }
        if (is_array($detail)) {
            $parts = [];
            foreach ($detail as $key => $item) {
                if (is_array($item) && isset($item['msg'])) {
                    $loc   = is_array($item['loc'] ?? null) ? end($item['loc']) : ($item['loc'] ?? null);
                    $parts[] = ($loc && $loc !== 'body' ? "{$loc}: " : '') . $item['msg'];
                } elseif (is_string($item)) {
                    $parts[] = is_string($key) ? "{$key}: {$item}" : $item;
                } elseif (is_array($item)) {
                    $parts[] = (is_string($key) ? "{$key}: " : '') . implode(', ', array_filter($item, 'is_string'));
                }
            }
            return mb_substr(implode('; ', $parts), 0, 300);
        }
        return '';
    }
}
