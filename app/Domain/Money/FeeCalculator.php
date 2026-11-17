<?php

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * The only place fee and total maths happens. Integer minor units only: no floats, no round().
 *
 * Fee = percentage of the subtotal (half-up) + a fixed amount per order. A free order has no fee.
 */
final class FeeCalculator
{
    private const BPS_DENOMINATOR = 10000;

    /** Half-up percentage: intdiv(x * bps + 5000, 10000). */
    public static function percentFee(int $amountMinor, int $bps): int
    {
        if ($amountMinor < 0 || $bps < 0) {
            throw new InvalidArgumentException('Amounts and rates cannot be negative.');
        }
        if ($bps > 0 && $amountMinor > intdiv(PHP_INT_MAX - intdiv(self::BPS_DENOMINATOR, 2), $bps)) {
            throw new InvalidArgumentException('Amount too large to calculate a fee without overflow.');
        }

        return intdiv($amountMinor * $bps + intdiv(self::BPS_DENOMINATOR, 2), self::BPS_DENOMINATOR);
    }

    public static function fee(int $subtotalMinor, int $bps, int $fixedMinor): int
    {
        if ($subtotalMinor === 0) {
            return 0;
        }

        return self::percentFee($subtotalMinor, $bps) + $fixedMinor;
    }

    public static function totals(int $subtotalMinor, int $bps, int $fixedMinor): OrderTotals
    {
        $fee = self::fee($subtotalMinor, $bps, $fixedMinor);

        return new OrderTotals($subtotalMinor, $fee, $subtotalMinor + $fee);
    }
}
