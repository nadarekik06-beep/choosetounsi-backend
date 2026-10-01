<?php

namespace App\Exceptions;

/** A flash sale's quota ran out between the cart and the order: the order is refused, never charged at full price silently. */
class FlashSaleSoldOut extends \RuntimeException
{
    public function __construct(public readonly string $productName)
    {
        parent::__construct("Flash sale sold out for {$productName}");
    }
}
