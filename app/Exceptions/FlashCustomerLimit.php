<?php

namespace App\Exceptions;

/** A customer would buy more flash-priced units than the flash sale allows per customer (AUDIT L-4). */
class FlashCustomerLimit extends \RuntimeException
{
    public function __construct(public readonly string $productName, public readonly int $limit, public readonly int $bought)
    {
        parent::__construct("Flash sale limit per customer reached for {$productName}");
    }
}
