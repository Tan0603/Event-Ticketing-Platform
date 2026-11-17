<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

class ListPublicEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('ticketing.pagination.max_per_page')],
        ];
    }
}
