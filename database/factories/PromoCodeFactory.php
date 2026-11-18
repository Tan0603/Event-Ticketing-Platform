<?php

namespace Database\Factories;

use App\Enums\PromoDiscountType;
use App\Models\Tenant\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PromoCode> */
class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'PROMO'.Str::upper(Str::random(6)),
            'discount_type' => PromoDiscountType::Percent,
            'percent_bps' => 1000,
            'amount_minor' => null,
            'is_active' => true,
        ];
    }

    public function percent(int $bps): static
    {
        return $this->state(['discount_type' => PromoDiscountType::Percent, 'percent_bps' => $bps, 'amount_minor' => null]);
    }

    public function fixed(int $amountMinor): static
    {
        return $this->state(['discount_type' => PromoDiscountType::Fixed, 'percent_bps' => null, 'amount_minor' => $amountMinor]);
    }
}
