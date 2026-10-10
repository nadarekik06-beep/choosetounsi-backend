<?php

namespace App\Services\Orders;

/** A cart that can't be priced or ordered as is: shown to the customer. */
class PricingException extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422, public ?string $errorCode = null, public array $data = [])
    {
        parent::__construct($message);
    }

    public function toResponse(): \Illuminate\Http\JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'code'    => $this->errorCode,
            'message' => $this->getMessage(),
            'data'    => $this->data ?: null,
        ], fn ($v) => $v !== null), $this->status);
    }
}
