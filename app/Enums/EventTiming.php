<?php

namespace App\Enums;

/** Where "now" sits relative to the event. Computed on read, never stored. */
enum EventTiming: string
{
    case Upcoming = 'upcoming';
    case Ongoing = 'ongoing';
    case Ended = 'ended';
}
