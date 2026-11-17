<?php

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * The only place fee and total maths happens. Integer minor units only: no floats, no round().
 *
 * The booking fee is charged PER TICKET: percentage of that ticket's unit price (half-up) plus a fixed amount.
 * A free ticket carries no fee (assumption, see README). A line's fee is the ticket fee times its quantity, so a
 * line total and the order total always add up exactly.
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

    /** Fee for ONE ticket: percent of its unit price (rounded half-up) + the fixed fee. Free tickets pay none. */
    public static function ticketFee(int $unitPriceMinor, int $bps, int $fixedMinor): int
    {
        if ($unitPriceMinor === 0) {
            return 0;
        }

        return self::percentFee($unitPriceMinor, $bps) + $fixedMinor;
    }

    /**
     * Totals for an order given its lines as [unit price minor, quantity] pairs.
     *
     * @param  array<int, array{0: int, 1: int}>  $lines
     */
    public static function totals(array $lines, int $bps, int $fixedMinor): OrderTotals
    {
        $subtotal = 0;
        $fees = 0;

        foreach ($lines as [$unitPrice, $quantity]) {
            $subtotal += $unitPrice * $quantity;
            $fees += self::ticketFee($unitPrice, $bps, $fixedMinor) * $quantity;
        }

        return new OrderTotals($subtotal, $fees, $subtotal + $fees);
    }
}
