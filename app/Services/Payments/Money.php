<?php

namespace App\Services\Payments;

/** Dinar amounts in French-style text: 50 → "50", 12.5 → "12,500", 1250 → "1 250". */
class Money
{
    public static function plain($value): string
    {
        $v = round((float) $value, 3);
        return floor($v) == $v
            ? number_format($v, 0, ',', ' ')
            : number_format($v, 3, ',', ' ');
    }

    public static function dt($value): string
    {
        return self::plain($value) . ' DT';
    }
}
