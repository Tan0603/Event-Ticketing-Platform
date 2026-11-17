<?php

namespace App\Actions\Tenancy;

use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenantJob;
use App\Models\Central\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Validates nothing itself (the FormRequest / command already did); inserts the central row and queues provisioning.
 *
 * The row is created with a plain INSERT. NEVER updateOrCreate/firstOrCreate on tenants:
 * registration must not be able to touch an existing tenant.
 */
class RegisterTenant
{
    /** Insert the central row only (status = provisioning). Used directly by `tenant:provision`. */
    public function create(RegisterTenantData $data): Tenant
    {
        try {
            return Tenant::query()->create([
                'id' => $data->id,
                'name' => $data->name,
                'status' => TenantStatus::Provisioning,
                'currency' => $data->currency,
                'timezone' => $data->timezone,
                'fee_percent_bps' => $data->feePercentBps,
                'fee_fixed_minor' => $data->feeFixedMinor,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A racing sign-up won the PRIMARY KEY: same clean 422 as the validation rule, never a 500.
            throw ValidationException::withMessages([
                'tenant_id' => [trans('validation.unique', ['attribute' => 'tenant id'])],
            ]);
        }
    }

    /** Insert the central row and queue provisioning (the HTTP path). */
    public function execute(RegisterTenantData $data): Tenant
    {
        $tenant = $this->create($data);

        ProvisionTenantJob::dispatch($data->id, $data->adminName, $data->adminEmail, $data->passwordHash);

        return $tenant;
    }
}
