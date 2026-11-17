<?php

use App\Actions\Tenancy\ProvisionTenant;
use App\Actions\Tenancy\RegisterTenant;
use App\Actions\Tenancy\RegisterTenantData;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// DatabaseTruncation (migrate once, truncate between tests) instead of RefreshDatabase's
// transaction: CREATE DATABASE cannot run inside a transaction, and provisioning creates databases.
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Feature');

/**
 * Tables in the CENTRAL database. Always asks the central connection explicitly: the `Schema` facade caches
 * whichever connection it first resolved (after a tenant run, the tenant one), which makes checks meaningless.
 *
 * @return array<int, string>
 */
function centralTables(): array
{
    $connection = DB::connection(config('tenancy.database.central_connection'));

    return collect($connection->getSchemaBuilder()->getTableListing())
        ->map(fn (string $table) => str_contains($table, '.') ? explode('.', $table)[1] : $table)
        ->sort()->values()->all();
}

/**
 * Create and fully provision a real tenant (own database, migrations, admin). Dropped after the test.
 */
function provisionTenant(string $id = 'acme-events', string $adminEmail = 'admin@acme.test', string $password = 'secret-pass-1'): Tenant
{
    $data = new RegisterTenantData(
        id: $id,
        name: 'Acme Events',
        currency: 'MYR',
        timezone: 'Asia/Kuala_Lumpur',
        feePercentBps: 500,
        feeFixedMinor: 50,
        adminName: 'Acme Admin',
        adminEmail: $adminEmail,
        passwordHash: Hash::make($password),
    );

    app(RegisterTenant::class)->create($data);

    return app(ProvisionTenant::class)->run($id, $data->adminName, $adminEmail, $data->passwordHash);
}
