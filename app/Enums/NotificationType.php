<?php

namespace App\Enums;

/** The e-mails an order can trigger. At most one of each type per order (UNIQUE order_id + type). */
enum NotificationType: string
{
    case Confirmation = 'confirmation';
    case Reminder = 'reminder';
    case Refund = 'refund';
    case EventCancelled = 'event_cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
