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
 *
 * Event-level shared capacity: when the event has a `capacity`, every change of sold + held also moves
 * `events.reserved` by the same amount with its own conditional UPDATE, in the same transaction and BEFORE the
 * ticket type is touched (the event row is already locked by EventLock, so the lock order is unchanged). Events
 * without a capacity skip that statement entirely.
 */
final class TicketStock
{
    /**
     * Reserve `$quantity` tickets for a pending order. Returns the tier's name and price at the moment of the
     * hold (the snapshot for the order), or null when the tier cannot be bought right now: unknown / other
     * event, not enough stock, more than max_per_order, outside its sales window, or still locked behind the
     * previous tier (tier progression).
     *
     * Tier progression is part of the same statement: a tier with `unlocks_after_ticket_type_id` is buyable only
     * when that predecessor is "done" (its stock is gone, or its sales window has ended), judged by the
     * statement's own snapshot, so there is no PHP pre-check that could go stale.
     *
     * @return array{id: int, name: string, price_minor: int}|null
     */
    public static function hold(int $ticketTypeId, int $eventId, int $quantity, CarbonInterface $now, ?bool $eventCapped = null): ?array
    {
        // The event counter first: it fails when the event as a whole has no room left, whatever the tier has.
        $capped = $eventCapped ?? DB::table('events')->where('id', $eventId)->whereNotNull('capacity')->exists();
        if ($capped && ! self::reserve($eventId, $quantity, $now)) {
            return null;
        }

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
                AND (tt.unlocks_after_ticket_type_id IS NULL OR EXISTS (
                      SELECT 1 FROM ticket_types AS pre
                       WHERE pre.id = tt.unlocks_after_ticket_type_id
                         AND (pre.sold + pre.held >= pre.quantity OR COALESCE(pre.sales_end_at, e.ends_at) <= ?)))
          RETURNING tt.id, tt.name, tt.price_minor',
            [$quantity, $now, $ticketTypeId, $eventId, $quantity, $quantity, $now, $now, $now],
        );

        if ($rows === []) {
            if ($capped) {
                self::unreserve($eventId, $quantity); // the tier refused: undo the event counter
            }

            return null;
        }

        return ['id' => (int) $rows[0]->id, 'name' => (string) $rows[0]->name, 'price_minor' => (int) $rows[0]->price_minor];
    }

    /** Give back a hold (order failed or expired). */
    public static function release(int $ticketTypeId, int $quantity): void
    {
        self::unreserveForTier($ticketTypeId, $quantity);
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
        $eventId = self::cappedEventId($ticketTypeId);
        if ($eventId !== null && ! self::reserve($eventId, $quantity, now())) {
            return false; // the event as a whole is full
        }

        $sold = DB::update(
            'UPDATE ticket_types SET sold = sold + ?, updated_at = ? WHERE id = ? AND sold + held + ? <= quantity',
            [$quantity, now(), $ticketTypeId, $quantity],
        ) === 1;

        if (! $sold && $eventId !== null) {
            self::unreserve($eventId, $quantity);
        }

        return $sold;
    }

    /** Return sold tickets to stock (refund). */
    public static function returnSold(int $ticketTypeId, int $quantity): void
    {
        self::unreserveForTier($ticketTypeId, $quantity);
        self::mustChangeOne(
            DB::update('UPDATE ticket_types SET sold = sold - ?, updated_at = ? WHERE id = ? AND sold >= ?', [$quantity, now(), $ticketTypeId, $quantity]),
            'return',
        );
    }

    /** Take room from the event's shared capacity. False when it does not fit (only ever called for events with a capacity). */
    private static function reserve(int $eventId, int $quantity, CarbonInterface $now): bool
    {
        return DB::update(
            'UPDATE events SET reserved = reserved + ?, updated_at = ? WHERE id = ? AND capacity IS NOT NULL AND reserved + ? <= capacity',
            [$quantity, $now, $eventId, $quantity],
        ) === 1;
    }

    private static function unreserve(int $eventId, int $quantity): void
    {
        self::mustChangeOne(
            DB::update('UPDATE events SET reserved = reserved - ?, updated_at = ? WHERE id = ? AND reserved >= ?', [$quantity, now(), $eventId, $quantity]),
            'unreserve',
        );
    }

    private static function unreserveForTier(int $ticketTypeId, int $quantity): void
    {
        $eventId = self::cappedEventId($ticketTypeId);
        if ($eventId !== null) {
            self::unreserve($eventId, $quantity);
        }
    }

    /** The event of a ticket type when that event has a shared capacity, else null (nothing to keep in sync). */
    private static function cappedEventId(int $ticketTypeId): ?int
    {
        $id = DB::table('ticket_types as tt')->join('events as e', 'e.id', '=', 'tt.event_id')
            ->where('tt.id', $ticketTypeId)->whereNotNull('e.capacity')->value('e.id');

        return $id === null ? null : (int) $id;
    }

    private static function mustChangeOne(int $affected, string $operation): void
    {
        // A miss here means the counters and the orders disagree: a bug, never a client mistake.
        if ($affected !== 1) {
            throw new LogicException("Inventory counter out of sync during {$operation}.");
        }
    }
}
