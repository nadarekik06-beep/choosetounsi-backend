<?php

namespace App\Exceptions;

/**
 * A coupon reached its total or per-customer limit between the quote and the
 * order (another order took the last use): the order is refused, the customer
 * gets a clear message (AUDIT M-2 / M-3).
 */
class CouponExhausted extends \RuntimeException
{
    public function __construct(public readonly string $couponCode)
    {
        parent::__construct("Coupon {$couponCode} usage limit was reached.");
    }
}
