<?php

namespace App\Http\Resources\Admin;

use App\Models\Tenant\PromoCode;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PromoCode */
class PromoCodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'discount_type' => $this->discount_type->value,
            'percent_bps' => $this->percent_bps,
            'amount_minor' => $this->amount_minor,
            'event_id' => $this->event_id,
            'max_uses' => $this->max_uses,
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'starts_at' => TenantTime::iso($this->starts_at),
            'ends_at' => TenantTime::iso($this->ends_at),
            'is_active' => $this->is_active,
            'created_at' => TenantTime::iso($this->created_at),
            'updated_at' => TenantTime::iso($this->updated_at),
        ];
    }
}
