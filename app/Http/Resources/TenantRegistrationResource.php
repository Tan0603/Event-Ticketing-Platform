<?php

namespace App\Http\Resources;

use App\Enums\TenantStatus;
use App\Models\Central\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantRegistrationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'tenant_id' => $this->id,
            'status' => $this->status->value,
            // Internal failure details are never exposed to the public endpoint.
            'message' => $this->status === TenantStatus::Failed
                ? 'Provisioning failed. Please contact support.'
                : null,
        ];
    }
}
