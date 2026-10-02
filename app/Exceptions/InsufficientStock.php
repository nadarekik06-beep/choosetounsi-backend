<?php

namespace App\Exceptions;

use RuntimeException;

/** Not enough units left to reserve an order line (checkout race, or re-opening a cancelled order). */
class InsufficientStock extends RuntimeException
{
    public function __construct(public readonly string $label, public readonly int $available)
    {
        parent::__construct("Not enough stock for {$label} ({$available} left).");
    }
}
