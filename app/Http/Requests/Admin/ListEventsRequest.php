<?php

namespace App\Http\Requests\Admin;

use App\Enums\EventStatus;
use App\Models\Tenant\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Event::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(EventStatus::values())],
            'q' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'sort' => ['sometimes', Rule::in(['starts_at', 'created_at', 'title'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('ticketing.pagination.max_per_page')],
        ];
    }
}
