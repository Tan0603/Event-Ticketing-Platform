<?php

namespace App\Http\Requests\Admin;

use App\Enums\PromoDiscountType;
use App\Models\Tenant\PromoCode;
use App\Support\TenantTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PromoCode::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => PromoCode::normalize($this->input('code'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promo_codes', 'code')],
            'discount_type' => ['required', Rule::enum(PromoDiscountType::class)],
            // Basis points: 1000 = 10.00 %. Required for `percent`, not allowed for `fixed`.
            'percent_bps' => ['required_if:discount_type,percent', 'prohibited_if:discount_type,fixed', 'nullable', 'integer', 'min:1', 'max:10000'],
            // Minor units (5000 = RM 50.00). Required for `fixed`, not allowed for `percent`.
            'amount_minor' => ['required_if:discount_type,fixed', 'prohibited_if:discount_type,percent', 'nullable', 'integer', 'min:1', 'max:9999999999'],
            'event_id' => ['nullable', 'integer', 'min:1', Rule::exists('events', 'id')],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function promoAttributes(): array
    {
        return self::convertDates($this->validated(), array_keys($this->rules()));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public static function convertDates(array $data, array $keys): array
    {
        $out = array_intersect_key($data, array_flip($keys));

        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($out[$field])) {
                $out[$field] = TenantTime::parse($out[$field]);
            }
        }

        return $out;
    }
}
