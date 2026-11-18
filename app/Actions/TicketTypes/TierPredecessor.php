<?php

namespace App\Actions\TicketTypes;

use App\Models\Tenant\TicketType;
use Illuminate\Validation\ValidationException;

/**
 * Rules for `unlocks_after_ticket_type_id` (tier progression): the previous tier must belong to the same event,
 * must not be the tier itself, and the chain must not loop (a loop would lock every tier in it for ever).
 * Used by the FormRequests for a friendly early answer and again by the actions, under the event lock, so two
 * concurrent edits cannot create a cycle between them.
 */
final class TierPredecessor
{
    public const FIELD = 'unlocks_after_ticket_type_id';

    /** @return string|null the validation message, or null when the choice is fine */
    public static function error(int $eventId, ?int $selfId, ?int $predecessorId): ?string
    {
        if ($predecessorId === null) {
            return null;
        }
        if ($selfId !== null && $predecessorId === $selfId) {
            return 'A ticket type cannot unlock after itself.';
        }

        /** @var array<int, int|null> $links ticket type id => its predecessor, for the whole event */
        $links = TicketType::query()->where('event_id', $eventId)->pluck(self::FIELD, 'id')
            ->map(fn ($id): ?int => $id === null ? null : (int) $id)->all();

        if (! array_key_exists($predecessorId, $links)) {
            return 'The previous tier must be a ticket type of the same event.';
        }

        $seen = [];
        for ($cursor = $predecessorId; $cursor !== null && ! isset($seen[$cursor]); $cursor = $links[$cursor] ?? null) {
            if ($cursor === $selfId) {
                return 'This would create a loop of tiers waiting for each other.';
            }
            $seen[$cursor] = true;
        }

        return null;
    }

    /** @throws ValidationException */
    public static function assertValid(int $eventId, ?int $selfId, ?int $predecessorId): void
    {
        if (($message = self::error($eventId, $selfId, $predecessorId)) !== null) {
            throw ValidationException::withMessages([self::FIELD => [$message]]);
        }
    }
}
