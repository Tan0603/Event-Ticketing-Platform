<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\ProvisionTenant;
use App\Actions\Tenancy\RegisterTenant;
use App\Actions\Tenancy\RegisterTenantData;
use App\Http\Requests\Central\RegisterTenantRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manual provisioning. Same validation rules and the same ProvisionTenant action as POST /api/register,
 * run synchronously.
 */
class ProvisionTenantCommand extends Command
{
    protected $signature = 'tenant:provision
        {tenant_id : Slug, e.g. sunsetfest}
        {name : Organizer name}
        {currency : ISO 4217 code, e.g. MYR}
        {timezone : IANA timezone, e.g. Asia/Kuala_Lumpur}
        {admin_email : Email of the first admin}
        {--admin-name=Admin : Name of the first admin}
        {--password= : Admin password (generated and printed once if omitted)}
        {--fee-bps= : Booking fee percent in basis points (default from config)}
        {--fee-fixed= : Booking fee fixed amount in minor units (default from config)}';

    protected $description = 'Create and provision a tenant synchronously (same code as POST /api/register)';

    public function handle(RegisterTenant $register, ProvisionTenant $provision): int
    {
        $generated = $this->option('password') === null || $this->option('password') === '';
        $password = $generated ? Str::password(16, symbols: false) : (string) $this->option('password');

        $input = [
            'tenant_id' => (string) $this->argument('tenant_id'),
            'name' => (string) $this->argument('name'),
            'currency' => strtoupper((string) $this->argument('currency')),
            'timezone' => (string) $this->argument('timezone'),
            'admin_email' => strtolower((string) $this->argument('admin_email')),
            'admin_name' => (string) $this->option('admin-name'),
            'password' => $password,
        ];

        $validator = Validator::make($input, RegisterTenantRequest::fieldRules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = new RegisterTenantData(
            id: $input['tenant_id'],
            name: $input['name'],
            currency: $input['currency'],
            timezone: $input['timezone'],
            feePercentBps: (int) ($this->option('fee-bps') ?? config('ticketing.default_fee_percent_bps')),
            feeFixedMinor: (int) ($this->option('fee-fixed') ?? config('ticketing.default_fee_fixed_minor')),
            adminName: $input['admin_name'],
            adminEmail: $input['admin_email'],
            passwordHash: Hash::make($password),
        );

        $tenant = $register->create($data);

        try {
            $tenant = $provision->run($data->id, $data->adminName, $data->adminEmail, $data->passwordHash);
        } catch (Throwable $e) {
            $provision->fail($data->id, $e->getMessage());
            $this->error("Provisioning failed and was cleaned up: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Tenant [{$tenant->id}] is {$tenant->status->value}.");
        $this->line("Admin:          {$data->adminEmail}");

        if ($generated) {
            $this->line("Admin password: {$password}  (shown once)");
        }

        $this->line("Webhook secret: {$tenant->webhook_secret}");

        return self::SUCCESS;
    }
}
