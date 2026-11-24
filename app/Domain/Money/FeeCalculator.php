<?php

namespace App\Domain\Money;

use App\Enums\PromoDiscountType;
use InvalidArgumentException;

/**
 * The only place fee and total maths happens. Integer minor units only: no floats, no round().
 *
 * The booking fee is charged PER TICKET: percentage of that ticket's unit price (half-up) plus a fixed amount.
 * A free ticket carries no fee (assumption, see README). A line's fee is the ticket fee times its quantity, so a
 * line total and the order total always add up exactly.
 *
 * A promo code takes money off the TICKET subtotal only (never below zero); fees stay those of the original
 * ticket prices. Order total = subtotal - discount + fees.
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
     * Discount of a promo code on a ticket subtotal: a percentage (half-up, integer maths) or a fixed amount,
     * never more than the subtotal, so the total can not go negative.
     *
     * @param  int  $value  basis points for a percent code, minor units for a fixed one
     */
    public static function discount(int $subtotalMinor, PromoDiscountType $type, int $value): int
    {
        if ($subtotalMinor < 0 || $value < 0) {
            throw new InvalidArgumentException('Amounts and rates cannot be negative.');
        }

        $discount = match ($type) {
            PromoDiscountType::Percent => self::percentFee($subtotalMinor, $value),
            PromoDiscountType::Fixed => $value,
        };

        return min($discount, $subtotalMinor);
    }

    /**
     * Totals for an order given its lines as [unit price minor, quantity] pairs, and optionally a promo code
     * (its type and value: basis points or minor units).
     *
     * @param  array<int, array{0: int, 1: int}>  $lines
     */
    public static function totals(array $lines, int $bps, int $fixedMinor, ?PromoDiscountType $promoType = null, int $promoValue = 0): OrderTotals
    {
        $subtotal = 0;
        $fees = 0;

        foreach ($lines as [$unitPrice, $quantity]) {
            $subtotal += $unitPrice * $quantity;
            $fees += self::ticketFee($unitPrice, $bps, $fixedMinor) * $quantity;
        }

        $discount = $promoType === null ? 0 : self::discount($subtotal, $promoType, $promoValue);

        return new OrderTotals($subtotal, $fees, $subtotal - $discount + $fees, $discount);
    }
}
