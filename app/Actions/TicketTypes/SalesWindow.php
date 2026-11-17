<?php

namespace App\Actions\TicketTypes;

use App\Models\Tenant\Event;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/** The sales window rules shared by creating and updating a ticket type. */
final class SalesWindow
{
    public static function assertValid(Event $event, ?CarbonInterface $start, ?CarbonInterface $end): void
    {
        $errors = [];

        if ($start !== null && $end !== null && $end->lte($start)) {
            $errors['sales_end_at'][] = 'The sales end must be after the sales start.';
        }
        if ($end !== null && $end->gt($event->ends_at)) {
            $errors['sales_end_at'][] = 'Sales cannot continue after the event has ended.';
        }
        if ($start !== null && $start->gte($event->ends_at)) {
            $errors['sales_start_at'][] = 'Sales must start before the event ends.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
