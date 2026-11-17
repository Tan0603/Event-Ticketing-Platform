<?php

namespace App\Http\Resources\Storefront;

use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of the public event list (an array built by ListPublicEvents). */
class PublicEventListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'slug' => $row['slug'],
            'description' => $row['description'],
            'venue' => $row['venue'],
            'starts_at' => TenantTime::iso(CarbonImmutable::parse($row['starts_at'])),
            'ends_at' => TenantTime::iso(CarbonImmutable::parse($row['ends_at'])),
            'currency' => tenant('currency'),
            'from_price_minor' => $row['from_price_minor'],
            'sold_out' => $row['tickets_available'] === 0,
        ];
    }
}
