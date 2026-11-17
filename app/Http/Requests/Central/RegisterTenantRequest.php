<?php

namespace App\Http\Requests\Central;

use App\Actions\Tenancy\RegisterTenantData;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public, central route
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('admin_email'))) {
            $this->merge(['admin_email' => strtolower(trim($this->input('admin_email')))]);
        }
    }

    /**
     * Shared with the `tenant:provision` command so both entry points enforce the same rules.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tenant_id' => [
                'required', 'string', 'min:3', 'max:30',
                'regex:/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/',
                Rule::notIn(config('ticketing.reserved_tenant_ids')),
                Rule::unique(Tenant::class, 'id'),
            ],
            'currency' => ['required', 'string', Rule::in(array_keys(config('currencies')))],
            'timezone' => ['required', 'string', 'timezone:all'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', Password::min(8)],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /** Hashes the password here, inside the request: plaintext never reaches a job payload or the DB. */
    public function toData(): RegisterTenantData
    {
        $input = $this->validated();

        return new RegisterTenantData(
            id: $input['tenant_id'],
            name: $input['name'],
            currency: $input['currency'],
            timezone: $input['timezone'],
            feePercentBps: (int) config('ticketing.default_fee_percent_bps'),
            feeFixedMinor: (int) config('ticketing.default_fee_fixed_minor'),
            adminName: $input['admin_name'],
            adminEmail: $input['admin_email'],
            passwordHash: Hash::make($input['password']),
        );
    }
}
