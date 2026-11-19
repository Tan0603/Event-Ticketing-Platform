<?php

namespace App\Http\Requests\Admin;

use App\Enums\PromoDiscountType;
use App\Models\Tenant\PromoCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var PromoCode $promoCode */
        $promoCode = $this->route('promoCode');

        return $this->user()?->can('update', $promoCode) ?? false;
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
        /** @var PromoCode $promoCode */
        $promoCode = $this->route('promoCode');

        return [
            'code' => ['sometimes', 'required', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promo_codes', 'code')->ignore($promoCode->id)],
            'discount_type' => ['sometimes', 'required', Rule::enum(PromoDiscountType::class)],
            'percent_bps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999999999'],
            'event_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('events', 'id')],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function promoAttributes(): array
    {
        return StorePromoCodeRequest::convertDates($this->validated(), array_keys($this->rules()));
    }
}
