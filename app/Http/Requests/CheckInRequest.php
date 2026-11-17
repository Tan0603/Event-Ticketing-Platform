<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('check-in') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            // The gate the scanner is working: a ticket for another event is refused with a clear reason.
            'event_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
