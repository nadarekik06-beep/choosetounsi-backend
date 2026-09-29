<?php

namespace App\Exceptions\Ads;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A sponsoring business rule said no (budget too low, product not ready, …).
 * Renders as the API's usual error shape with a machine-readable code the
 * seller dashboard translates: { success: false, message, code, ...extra }.
 */
class AdRuleViolation extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    /** Message from resources/lang/{locale}/ads.php errors.<code>. */
    public static function make(string $code, array $extra = [], array $params = [], int $status = 422): static
    {
        return new static(strtoupper($code), __('ads.errors.' . strtolower($code), $params), $extra, $status);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['success' => false, 'message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra,
            $this->status
        );
    }
}
