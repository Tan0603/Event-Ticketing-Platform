<?php

namespace App\Http\Requests\Admin;

use App\Models\Tenant\TicketType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var TicketType $ticketType */
        $ticketType = $this->route('ticketType');

        return $this->user()?->can('update', $ticketType) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price_minor' => ['sometimes', 'required', 'integer', 'min:0', 'max:9999999999'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'],
            'max_per_order' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.config('ticketing.max_per_order_cap')],
            'sales_start_at' => ['sometimes', 'nullable', 'date'],
            'sales_end_at' => ['sometimes', 'nullable', 'date'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, mixed> */
    public function ticketTypeAttributes(): array
    {
        return StoreTicketTypeRequest::convertDates($this->validated(), array_keys($this->rules()));
    }
}
