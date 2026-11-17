<?php

namespace App\Enums;

/** Whether a ticket type can be bought right now. Computed on read, never stored. */
enum SalesStatus: string
{
    case NotPublished = 'not_published';
    case Cancelled = 'cancelled';
    case Upcoming = 'upcoming';
    case OnSale = 'on_sale';
    case SoldOut = 'sold_out';
    case Ended = 'ended';
}
