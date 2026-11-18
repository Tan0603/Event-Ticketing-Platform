<?php

namespace App\Actions\PromoCodes;

use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\PromoCode;
use App\Support\StorefrontCache;

class DeletePromoCode
{
    public function execute(PromoCode $promoCode): void
    {
        // Atomic: deletes only while no use is taken, however many checkouts are running.
        $deleted = PromoCode::query()->whereKey($promoCode->id)->where('used', 0)->delete();

        if ($deleted === 0) {
            if (! PromoCode::query()->whereKey($promoCode->id)->exists()) {
                return; // already gone
            }

            throw new BusinessRuleException('promo_code_in_use', 'A promo code that was used cannot be deleted; deactivate it with is_active=false instead.');
        }

        StorefrontCache::flush();
    }
}
