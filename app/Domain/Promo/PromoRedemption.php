<?php

namespace App\Domain\Promo;

use App\Enums\PromoDiscountType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only code allowed to change `promo_codes.used`.
 *
 * Like TicketStock, every method is ONE atomic conditional UPDATE: PostgreSQL decides under the row lock whether
 * the code can still be redeemed. Nothing is read, checked in PHP and written back, so two checkouts racing for the
 * last use cannot both win. Lock order: the promo row comes AFTER the ticket types (event -> order -> ticket types
 * -> promo code -> tickets).
 */
final class PromoRedemption
{
    /**
     * Take one use of the code for an order of `$eventId`. Returns the discount rule as it was at that moment
     * (the snapshot for the order), or null when the code cannot be redeemed right now: inactive, another event's
     * code, outside its window, or every use already taken.
     *
     * @return array{id: int, code: string, type: PromoDiscountType, value: int}|null value = basis points or minor units
     */
    public static function redeem(int $promoCodeId, int $eventId, CarbonInterface $now): ?array
    {
        $rows = DB::select(
            'UPDATE promo_codes
                SET used = used + 1, updated_at = ?
              WHERE id = ?
                AND is_active
                AND (max_uses IS NULL OR used < max_uses)
                AND (event_id IS NULL OR event_id = ?)
                AND (starts_at IS NULL OR starts_at <= ?)
                AND (ends_at IS NULL OR ends_at > ?)
          RETURNING id, code, discount_type, percent_bps, amount_minor',
            [$now, $promoCodeId, $eventId, $now, $now],
        );

        if ($rows === []) {
            return null;
        }

        $type = PromoDiscountType::from((string) $rows[0]->discount_type);

        return [
            'id' => (int) $rows[0]->id,
            'code' => (string) $rows[0]->code,
            'type' => $type,
            'value' => (int) ($type === PromoDiscountType::Percent ? $rows[0]->percent_bps : $rows[0]->amount_minor),
        ];
    }

    /** Give a use back (the order failed or expired). Never goes below zero. */
    public static function giveBack(int $promoCodeId): void
    {
        DB::update('UPDATE promo_codes SET used = used - 1, updated_at = ? WHERE id = ? AND used > 0', [now(), $promoCodeId]);
    }

    /**
     * Take a use again for an order that was already priced with the code (a late payment on an expired order).
     * Best effort: false when the code is used up meanwhile; the order keeps its discount (it was quoted to the buyer).
     */
    public static function retake(int $promoCodeId): bool
    {
        return DB::update(
            'UPDATE promo_codes SET used = used + 1, updated_at = ? WHERE id = ? AND (max_uses IS NULL OR used < max_uses)',
            [now(), $promoCodeId],
        ) === 1;
    }
}
