<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Valid = 'valid';
    case CheckedIn = 'checked_in';
    case Void = 'void';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
