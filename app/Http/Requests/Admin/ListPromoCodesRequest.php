<?php

namespace App\Http\Requests\Admin;

use App\Models\Tenant\PromoCode;
use Illuminate\Foundation\Http\FormRequest;

class ListPromoCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PromoCode::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'event_id' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('ticketing.pagination.max_per_page')],
        ];
    }
}
