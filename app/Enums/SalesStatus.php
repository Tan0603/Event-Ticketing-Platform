<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/** Whether a ticket type can be bought right now. Computed on read, never stored. */
enum SalesStatus: string
{
    case NotPublished = 'not_published';
    case Cancelled = 'cancelled';
    case Scheduled = 'scheduled';
    case OnSale = 'on_sale';
    case SoldOut = 'sold_out';
    case Ended = 'ended';
    /** Waits for the previous tier (tier progression) to sell out or end. */
    case Locked = 'locked';

    /**
     * The single place that decides a tier's sales status, shared by the admin API (live rows) and the
     * storefront (cached rows + fresh counters). Display only: checkout re-checks everything in the database.
     */
    public static function resolve(
        EventStatus $eventStatus,
        ?CarbonInterface $salesStart,
        CarbonInterface $effectiveSalesEnd,
        int $available,
        CarbonInterface $now,
        bool $locked = false,
    ): self {
        return match (true) {
            $eventStatus === EventStatus::Cancelled => self::Cancelled,
            $eventStatus === EventStatus::Draft => self::NotPublished,
            $salesStart !== null && $now->lt($salesStart) => self::Scheduled,
            $now->gte($effectiveSalesEnd) => self::Ended,
            $available <= 0 => self::SoldOut,
            $locked => self::Locked,
            default => self::OnSale,
        };
    }
}
