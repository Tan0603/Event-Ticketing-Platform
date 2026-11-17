<?php

namespace App\Http\Requests\Admin;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAttendeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ticket_type_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(TicketStatus::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function ticketTypeId(): ?int
    {
        return $this->filled('ticket_type_id') ? (int) $this->input('ticket_type_id') : null;
    }

    public function status(): ?TicketStatus
    {
        return $this->filled('status') ? TicketStatus::from((string) $this->input('status')) : null;
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }
}
