<?php

namespace App\Domain\Inventory;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only code allowed to change `ticket_types.sold` / `held`.
 *
 * Every method is ONE atomic conditional UPDATE: PostgreSQL decides, under the row lock, whether the stock is
 * there. Nothing is read, checked in PHP and written back, so concurrent checkouts can never oversell.
 * The CHECK (sold + held <= quantity) constraint stays as the last line of defence.
 */
final class TicketStock
{
    /**
     * Reserve `$quantity` tickets for a pending order. Returns the tier's name and price at the moment of the
     * hold (the snapshot for the order), or null when the tier cannot be bought right now: unknown / other
     * event, not enough stock, more than max_per_order, or outside its sales window.
     *
     * @return array{id: int, name: string, price_minor: int}|null
     */
    public static function hold(int $ticketTypeId, int $eventId, int $quantity, CarbonInterface $now): ?array
    {
        $rows = DB::select(
            'UPDATE ticket_types AS tt
                SET held = tt.held + ?, updated_at = ?
               FROM events AS e
              WHERE tt.id = ?
                AND tt.event_id = ?
                AND e.id = tt.event_id
                AND tt.sold + tt.held + ? <= tt.quantity
                AND ? <= tt.max_per_order
                AND (tt.sales_start_at IS NULL OR tt.sales_start_at <= ?)
                AND COALESCE(tt.sales_end_at, e.ends_at) > ?
          RETURNING tt.id, tt.name, tt.price_minor',
            [$quantity, $now, $ticketTypeId, $eventId, $quantity, $quantity, $now, $now],
        );

        if ($rows === []) {
            return null;
        }

        return ['id' => (int) $rows[0]->id, 'name' => (string) $rows[0]->name, 'price_minor' => (int) $rows[0]->price_minor];
    }

    /** Give back a hold (order failed or expired). */
    public static function release(int $ticketTypeId, int $quantity): void
    {
        self::mustChangeOne(
            DB::update('UPDATE ticket_types SET held = held - ?, updated_at = ? WHERE id = ? AND held >= ?', [$quantity, now(), $ticketTypeId, $quantity]),
            'release',
        );
    }

    /** Turn a hold into a sale (payment confirmed). sold + held is unchanged, so the CHECK always holds. */
    public static function commitHold(int $ticketTypeId, int $quantity): void
    {
        self::mustChangeOne(
            DB::update('UPDATE ticket_types SET held = held - ?, sold = sold + ?, updated_at = ? WHERE id = ? AND held >= ?', [$quantity, $quantity, now(), $ticketTypeId, $quantity]),
            'commit',
        );
    }

    /** Sell tickets directly, without a hold (a late payment for an expired order). False when they are gone. */
    public static function sellWithoutHold(int $ticketTypeId, int $quantity): bool
    {
        return DB::update(
            'UPDATE ticket_types SET sold = sold + ?, updated_at = ? WHERE id = ? AND sold + held + ? <= quantity',
            [$quantity, now(), $ticketTypeId, $quantity],
        ) === 1;
    }

    /** Return sold tickets to stock (refund). */
    public static function returnSold(int $ticketTypeId, int $quantity): void
    {
        self::mustChangeOne(
            DB::update('UPDATE ticket_types SET sold = sold - ?, updated_at = ? WHERE id = ? AND sold >= ?', [$quantity, now(), $ticketTypeId, $quantity]),
            'return',
        );
    }

    private static function mustChangeOne(int $affected, string $operation): void
    {
        // A miss here means the counters and the orders disagree: a bug, never a client mistake.
        if ($affected !== 1) {
            throw new LogicException("Inventory counter out of sync during {$operation}.");
        }
    }
}
