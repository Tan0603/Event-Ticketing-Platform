<?php

namespace App\Actions\Events;

use Carbon\CarbonImmutable;

final readonly class EventData
{
    public function __construct(
        public string $title,
        public ?string $slug,
        public ?string $description,
        public string $venueName,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?string $venueAddress = null,
        public ?int $capacity = null,
    ) {}
}
