<?php

namespace App\Actions\PromoCodes;

use App\Enums\PromoDiscountType;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\PromoCode;
use App\Support\StorefrontCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePromoCode
{
    /** @param array<string, mixed> $attributes only the keys the client sent */
    public function execute(PromoCode $promoCode, array $attributes): PromoCode
    {
        try {
            $updated = DB::transaction(function () use ($promoCode, $attributes): PromoCode {
                // The promo row is last in the lock order; while we hold it no checkout can change `used`.
                /** @var PromoCode $locked */
                $locked = PromoCode::query()->lockForUpdate()->findOrFail($promoCode->id);
                $locked->fill($attributes);

                // Keep the type/value pairing consistent when the type changes (the DB CHECK is the last guard).
                if ($locked->discount_type === PromoDiscountType::Percent) {
                    $locked->amount_minor = null;
                } else {
                    $locked->percent_bps = null;
                }

                $this->assertValid($locked);
                $locked->save();

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => ['This promo code already exists.']]);
        }

        StorefrontCache::flush();

        return $updated->refresh();
    }

    private function assertValid(PromoCode $promo): void
    {
        $errors = [];

        if ($promo->discount_type === PromoDiscountType::Percent && $promo->percent_bps === null) {
            $errors['percent_bps'][] = 'A percent code needs percent_bps.';
        }
        if ($promo->discount_type === PromoDiscountType::Fixed && $promo->amount_minor === null) {
            $errors['amount_minor'][] = 'A fixed code needs amount_minor.';
        }
        if ($promo->starts_at !== null && $promo->ends_at !== null && $promo->ends_at->lte($promo->starts_at)) {
            $errors['ends_at'][] = 'The end must be after the start.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($promo->max_uses !== null && $promo->max_uses < $promo->used) {
            throw new BusinessRuleException('max_uses_below_used', "max_uses cannot be lower than the {$promo->used} uses already taken.");
        }
    }
}
