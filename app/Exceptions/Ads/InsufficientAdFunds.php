<?php

namespace App\Exceptions\Ads;

/** The ad wallet (credit + balance) can't cover a charge. */
class InsufficientAdFunds extends AdRuleViolation
{
    public static function for(float $needed, float $available): static
    {
        return self::make('wallet_too_low', [
            'required'  => round($needed, 3),
            'available' => round($available, 3),
        ], ['required' => number_format($needed, 3), 'available' => number_format($available, 3)], 402);
    }
}
