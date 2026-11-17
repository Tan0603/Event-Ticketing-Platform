<?php

namespace App\Jobs;

use App\Actions\Tenancy\ProvisionTenant;
use App\Support\TenantKey;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The ONLY central job: it runs outside tenant context because the tenant does not exist yet.
 * Carries the password HASH (never plaintext) as allowed by the spec.
 */
class ProvisionTenantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $adminName,
        public readonly string $adminEmail,
        public readonly string $passwordHash,
    ) {
        $this->onQueue('provisioning');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function uniqueId(): string
    {
        return TenantKey::central('provision-job', $this->tenantId);
    }

    public function handle(ProvisionTenant $provision): void
    {
        $provision->run($this->tenantId, $this->adminName, $this->adminEmail, $this->passwordHash);
    }

    /** Called once, after the final retry: mark failed and drop the database. */
    public function failed(Throwable $exception): void
    {
        app(ProvisionTenant::class)->fail($this->tenantId, $exception->getMessage());
    }
}
