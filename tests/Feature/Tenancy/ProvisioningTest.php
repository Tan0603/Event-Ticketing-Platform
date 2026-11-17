<?php

use App\Actions\Tenancy\ProvisionTenant;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Jobs\ProvisionTenantJob;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Support\TenantDatabase;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

function tenantUsers(Tenant $tenant): Collection
{
    return $tenant->run(fn () => User::query()->get());
}

describe('ProvisionTenant', function () {
    it('creates the database, runs migrations, creates the admin, generates the secret and activates', function () {
        $tenant = provisionTenant('acme-events', 'Admin@Acme.test', 'secret-pass-1');

        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and(TenantDatabase::exists($tenant))->toBeTrue()
            ->and($tenant->webhook_secret)->toMatch('/^[a-f0-9]{64}$/');

        $users = tenantUsers($tenant);
        expect($users)->toHaveCount(1)
            ->and($users[0]->email)->toBe('admin@acme.test')
            ->and($users[0]->role)->toBe(UserRole::Admin)
            ->and(Hash::check('secret-pass-1', $users[0]->password))->toBeTrue();

        expect($tenant->run(fn () => Schema::hasTable('users')))->toBeTrue();
        expect(tenancy()->initialized)->toBeFalse(); // back in central context
    });

    it('keeps tenant data out of the central database', function () {
        provisionTenant('acme-events');

        expect(Schema::hasTable('users'))->toBeFalse();
    });

    it('gives each tenant its own database', function () {
        $a = provisionTenant('acme-events', 'a@acme.test');
        $b = provisionTenant('beta-events', 'b@beta.test');

        expect(TenantDatabase::name($a))->not->toBe(TenantDatabase::name($b))
            ->and(tenantUsers($a)->pluck('email')->all())->toBe(['a@acme.test'])
            ->and(tenantUsers($b)->pluck('email')->all())->toBe(['b@beta.test']);
    });

    it('is idempotent: running twice changes nothing and creates no second admin', function () {
        $first = provisionTenant('acme-events');
        $secret = $first->webhook_secret;

        $again = app(ProvisionTenant::class)->run('acme-events', 'Other', 'other@acme.test', Hash::make('x'));

        expect($again->webhook_secret)->toBe($secret)
            ->and($again->status)->toBe(TenantStatus::Active)
            ->and(tenantUsers($again))->toHaveCount(1);
    });

    it('retries safely when the database already exists but nothing else ran', function () {
        $tenant = Tenant::factory()->provisioning()->create(['id' => 'acme-events']);
        TenantDatabase::ensureExists($tenant); // simulate: step 3 done, crashed before migrations

        $done = app(ProvisionTenant::class)->run('acme-events', 'Admin', 'admin@acme.test', Hash::make('x'));

        expect($done->status)->toBe(TenantStatus::Active)
            ->and(tenantUsers($done))->toHaveCount(1);
    });

    it('retries safely when migrations and the admin already exist but the tenant is still provisioning', function () {
        $tenant = Tenant::factory()->provisioning()->create(['id' => 'acme-events']);
        TenantDatabase::ensureExists($tenant);
        Artisan::call('tenants:migrate', ['--tenants' => ['acme-events']]);
        $tenant->run(fn () => User::factory()->create(['email' => 'admin@acme.test']));

        $done = app(ProvisionTenant::class)->run('acme-events', 'Admin', 'admin@acme.test', Hash::make('x'));

        expect($done->status)->toBe(TenantStatus::Active)
            ->and(tenantUsers($done))->toHaveCount(1);
    });

    it('never resurrects a failed tenant', function () {
        Tenant::factory()->failed()->create(['id' => 'acme-events']);

        $result = app(ProvisionTenant::class)->run('acme-events', 'Admin', 'admin@acme.test', Hash::make('x'));

        expect($result->status)->toBe(TenantStatus::Failed)
            ->and(TenantDatabase::exists($result))->toBeFalse();
    });
});

describe('failure cleanup', function () {
    it('marks the tenant failed and drops its database after a permanent failure', function () {
        $tenant = Tenant::factory()->provisioning()->create(['id' => 'acme-events']);
        TenantDatabase::ensureExists($tenant); // half-created
        expect(TenantDatabase::exists($tenant))->toBeTrue();

        (new ProvisionTenantJob('acme-events', 'Admin', 'admin@acme.test', Hash::make('x')))
            ->failed(new RuntimeException('boom'));

        $tenant->refresh();
        expect($tenant->status)->toBe(TenantStatus::Failed)
            ->and($tenant->failure_reason)->toBe('boom')
            ->and(TenantDatabase::exists($tenant))->toBeFalse(); // no orphan
    });

    it('is a no-op when the database was never created', function () {
        Tenant::factory()->provisioning()->create(['id' => 'acme-events']);

        (new ProvisionTenantJob('acme-events', 'Admin', 'a@a.test', Hash::make('x')))->failed(new RuntimeException('x'));

        expect(Tenant::query()->find('acme-events')->status)->toBe(TenantStatus::Failed);
    });

    it('never fails or drops an already active tenant', function () {
        $tenant = provisionTenant('acme-events');

        (new ProvisionTenantJob('acme-events', 'Admin', 'a@a.test', Hash::make('x')))->failed(new RuntimeException('late'));

        expect($tenant->fresh()->status)->toBe(TenantStatus::Active)
            ->and(TenantDatabase::exists($tenant))->toBeTrue()
            ->and(tenantUsers($tenant))->toHaveCount(1);
    });

    it('ignores an unknown tenant', function () {
        (new ProvisionTenantJob('ghost-org', 'Admin', 'a@a.test', Hash::make('x')))->failed(new RuntimeException('x'));

        expect(Tenant::query()->count())->toBe(0);
    });

    it('truncates long failure reasons', function () {
        Tenant::factory()->provisioning()->create(['id' => 'acme-events']);

        app(ProvisionTenant::class)->fail('acme-events', str_repeat('x', 2000));

        expect(strlen(Tenant::query()->find('acme-events')->failure_reason))->toBeLessThanOrEqual(500);
    });
});

describe('ProvisionTenantJob', function () {
    it('is a retried, unique job on the provisioning queue', function () {
        $job = new ProvisionTenantJob('acme-events', 'Admin', 'a@a.test', Hash::make('x'));

        expect($job->tries)->toBe(3)
            ->and($job->backoff())->toBe([10, 30, 90])
            ->and($job->queue)->toBe('provisioning')
            ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueId())->toBe('c:provision-job:acme-events');
    });

    it('runs the same provisioning action when handled', function () {
        Tenant::factory()->provisioning()->create(['id' => 'acme-events']);

        dispatch_sync(new ProvisionTenantJob('acme-events', 'Admin', 'admin@acme.test', Hash::make('x')));

        $tenant = Tenant::query()->find('acme-events');
        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and(tenantUsers($tenant))->toHaveCount(1);
    });
});
