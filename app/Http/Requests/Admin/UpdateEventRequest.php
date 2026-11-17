<?php

namespace App\Http\Requests\Admin;

use App\Models\Tenant\Event;
use App\Support\TenantTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Event $event */
        $event = $this->route('event');

        return $this->user()?->can('update', $event) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Event $event */
        $event = $this->route('event');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('events', 'slug')->ignore($event->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'venue_name' => ['sometimes', 'required', 'string', 'max:255'],
            'venue_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date'],
        ];
    }

    /**
     * Only the fields the client sent, with dates converted to UTC. Start/end ordering is checked by the
     * action against the stored values (a PATCH may send only one of them).
     *
     * @return array<string, mixed>
     */
    public function eventAttributes(): array
    {
        $data = $this->validated();

        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = TenantTime::parse($data[$field]);
            }
        }

        return $data;
    }
}
