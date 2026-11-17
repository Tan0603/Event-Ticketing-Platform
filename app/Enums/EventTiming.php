<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/** Where "now" sits relative to the event. Computed on read, never stored. */
enum EventTiming: string
{
    case Upcoming = 'upcoming';
    case Ongoing = 'ongoing';
    case Ended = 'ended';

    public static function resolve(CarbonInterface $now, CarbonInterface $startsAt, CarbonInterface $endsAt): self
    {
        return match (true) {
            $now->gte($endsAt) => self::Ended,
            $now->gte($startsAt) => self::Ongoing,
            default => self::Upcoming,
        };
    }
}
