<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Central\Tenant;
use App\Support\TenantDatabase;
use Illuminate\Console\Command;

/**
 * Frees a tenant id: a `failed` id is not reusable through the public endpoint (see README Assumptions).
 */
class PurgeTenantCommand extends Command
{
    protected $signature = 'tenant:purge {tenant_id} {--force : Also purge an active tenant (destroys its data)}';

    protected $description = 'Delete a tenant row and drop its database (frees a failed tenant id)';

    public function handle(): int
    {
        $tenant = Tenant::query()->find((string) $this->argument('tenant_id'));

        if ($tenant === null) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }

        if ($tenant->status === TenantStatus::Active && ! $this->option('force')) {
            $this->error('Tenant is active. Pass --force to destroy its data.');

            return self::FAILURE;
        }

        TenantDatabase::dropIfExists($tenant);
        $tenant->delete();

        $this->info("Tenant [{$tenant->id}] purged.");

        return self::SUCCESS;
    }
}
