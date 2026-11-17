<?php

namespace App\Http\Resources\Storefront;

use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One public event with its tiers (an array built by ShowPublicEvent). */
class PublicEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $event */
        $event = $this->resource;
        $currency = tenant('currency');

        return [
            'id' => $event['id'],
            'title' => $event['title'],
            'slug' => $event['slug'],
            'description' => $event['description'],
            'venue' => $event['venue'],
            'starts_at' => TenantTime::iso($event['starts_at']),
            'ends_at' => TenantTime::iso($event['ends_at']),
            'timing' => $event['timing'],
            'currency' => $currency,
            'ticket_types' => array_map(fn (array $tier): array => [
                'id' => $tier['id'],
                'name' => $tier['name'],
                'description' => $tier['description'],
                'price_minor' => $tier['price_minor'],
                'available' => $tier['available'],
                'max_per_order' => min($tier['max_per_order'], max($tier['available'], 0)),
                'sales_start_at' => TenantTime::iso($tier['sales_start_at']),
                'sales_end_at' => TenantTime::iso($tier['sales_end_at']),
                'sales_status' => $tier['sales_status'],
            ], $event['ticket_types']),
        ];
    }
}
