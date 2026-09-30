<?php

namespace App\Exceptions\Payments;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A payment-request rule said no (method disabled, amount out of range, already
 * decided…). Same API error shape as the ads errors: { success: false, message, code }.
 * Messages come from resources/lang/{locale}/payments.php errors.<code>.
 */
class PaymentRequestViolation extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, array $params = [], int $status = 422, array $extra = []): static
    {
        return new static(strtoupper($code), __('payments.errors.' . strtolower($code), $params), $extra, $status);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['success' => false, 'message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra,
            $this->status
        );
    }
}
