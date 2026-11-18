<?php

namespace App\Actions\PromoCodes;

use App\Models\Tenant\PromoCode;
use App\Support\StorefrontCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CreatePromoCode
{
    /** @param array<string, mixed> $attributes validated by StorePromoCodeRequest */
    public function execute(array $attributes): PromoCode
    {
        $this->assertWindow($attributes['starts_at'] ?? null, $attributes['ends_at'] ?? null);

        try {
            $promo = PromoCode::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            // The UNIQUE index is the real guard; this also covers two admins racing for one code.
            throw ValidationException::withMessages(['code' => ['This promo code already exists.']]);
        }

        StorefrontCache::flush();

        return $promo->refresh();
    }

    private function assertWindow(mixed $start, mixed $end): void
    {
        if ($start !== null && $end !== null && $end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => ['The end must be after the start.']]);
        }
    }
}
