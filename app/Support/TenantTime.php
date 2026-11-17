<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Timestamps are stored in UTC. The API accepts any ISO-8601 value (an offset wins; a bare local time is
 * read in the tenant's timezone) and shows times in the tenant's timezone.
 */
final class TenantTime
{
    public static function timezone(): string
    {
        return (string) (tenant('timezone') ?? config('app.timezone'));
    }

    public static function parse(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::timezone())->utc();
    }

    public static function iso(?CarbonInterface $time): ?string
    {
        return $time?->setTimezone(self::timezone())->toIso8601String();
    }
}
