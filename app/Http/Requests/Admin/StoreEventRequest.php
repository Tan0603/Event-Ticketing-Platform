<?php

namespace App\Http\Requests\Admin;

use App\Actions\Events\EventData;
use App\Models\Tenant\Event;
use App\Support\TenantTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Event::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('events', 'slug')],
            'description' => ['nullable', 'string', 'max:10000'],
            'venue_name' => ['required', 'string', 'max:255'],
            'venue_address' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // Shared by all ticket types of the event; omit or null for no event-level limit.
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ];
    }

    public function toData(): EventData
    {
        return new EventData(
            title: $this->validated('title'),
            slug: $this->validated('slug'),
            description: $this->validated('description'),
            venueName: $this->validated('venue_name'),
            startsAt: TenantTime::parse($this->validated('starts_at')),
            endsAt: TenantTime::parse($this->validated('ends_at')),
            venueAddress: $this->validated('venue_address'),
            capacity: $this->validated('capacity'),
        );
    }
}
