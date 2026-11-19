<?php

namespace App\Enums;

enum PromoDiscountType: string
{
    /** `percent_bps` basis points of the ticket subtotal (1000 = 10.00 %). */
    case Percent = 'percent';
    /** `amount_minor` minor units off the ticket subtotal. */
    case Fixed = 'fixed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
