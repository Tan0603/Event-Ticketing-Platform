<?php

namespace App\Domain\Money;

/** Display only: turns integer minor units into text ("MYR 105.50") with integer maths, no floats. */
final class MoneyFormatter
{
    public static function format(int $minor, string $currency): string
    {
        $exponent = (int) config("currencies.{$currency}.exponent", 2);
        $divisor = 10 ** $exponent;
        $absolute = abs($minor);

        $major = number_format(intdiv($absolute, $divisor), 0, '.', ',');
        $fraction = $exponent === 0 ? '' : '.'.str_pad((string) ($absolute % $divisor), $exponent, '0', STR_PAD_LEFT);

        return $currency.' '.($minor < 0 ? '-' : '').$major.$fraction;
    }
}
