<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use App\Models\Tenant\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Order::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'event_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'email' => ['sometimes', 'string', 'max:255'],
            'reference' => ['sometimes', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('ticketing.pagination.max_per_page')],
        ];
    }
}
