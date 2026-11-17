<?php

use App\Enums\TenantStatus;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Support\TenantDatabase;
use Illuminate\Support\Facades\Hash;

describe('tenant:provision', function () {
    it('provisions a tenant synchronously with the shared action', function () {
        $this->artisan('tenant:provision', [
            'tenant_id' => 'sunsetfest', 'name' => 'Sunset Fest', 'currency' => 'MYR',
            'timezone' => 'Asia/Kuala_Lumpur', 'admin_email' => 'Boss@Sunset.test',
            '--password' => 'my-own-password',
        ])->assertSuccessful()->expectsOutputToContain('is active');

        $tenant = Tenant::query()->findOrFail('sunsetfest');
        $admin = $tenant->run(fn () => User::query()->sole());

        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and($tenant->webhook_secret)->toMatch('/^[a-f0-9]{64}$/')
            ->and($admin->email)->toBe('boss@sunset.test')
            ->and(Hash::check('my-own-password', $admin->password))->toBeTrue();
    });

    it('generates and prints a password when none is given', function () {
        $this->artisan('tenant:provision', [
            'tenant_id' => 'sunsetfest', 'name' => 'Sunset Fest', 'currency' => 'SGD',
            'timezone' => 'Asia/Singapore', 'admin_email' => 'boss@sunset.test',
        ])->assertSuccessful()->expectsOutputToContain('Admin password:');
    });

    it('honours custom fee settings', function () {
        $this->artisan('tenant:provision', [
            'tenant_id' => 'sunsetfest', 'name' => 'Sunset Fest', 'currency' => 'MYR',
            'timezone' => 'Asia/Kuala_Lumpur', 'admin_email' => 'boss@sunset.test',
            '--fee-bps' => '250', '--fee-fixed' => '100',
        ])->assertSuccessful();

        $tenant = Tenant::query()->findOrFail('sunsetfest');
        expect($tenant->fee_percent_bps)->toBe(250)->and($tenant->fee_fixed_minor)->toBe(100);
    });

    it('enforces the same validation rules as the endpoint and creates nothing', function (array $args) {
        $this->artisan('tenant:provision', array_merge([
            'tenant_id' => 'sunsetfest', 'name' => 'Sunset Fest', 'currency' => 'MYR',
            'timezone' => 'Asia/Kuala_Lumpur', 'admin_email' => 'boss@sunset.test',
        ], $args))->assertFailed();

        expect(Tenant::query()->count())->toBe(0);
    })->with([
        'reserved id' => [['tenant_id' => 'admin']],
        'bad id' => [['tenant_id' => 'Bad_ID']],
        'bad currency' => [['currency' => 'ZZZ']],
        'bad timezone' => [['timezone' => 'Nowhere/Land']],
        'bad email' => [['admin_email' => 'nope']],
    ]);

    it('refuses an existing tenant and leaves it untouched', function () {
        $tenant = provisionTenant('sunsetfest', 'original@sunset.test', 'original-pass-1');
        $secret = $tenant->webhook_secret;

        $this->artisan('tenant:provision', [
            'tenant_id' => 'sunsetfest', 'name' => 'Hijack', 'currency' => 'MYR',
            'timezone' => 'Asia/Kuala_Lumpur', 'admin_email' => 'attacker@evil.test',
            '--password' => 'attacker-pass-1',
        ])->assertFailed();

        $fresh = Tenant::query()->findOrFail('sunsetfest');
        $users = $fresh->run(fn () => User::query()->get());

        expect($fresh->name)->toBe('Acme Events')
            ->and($fresh->webhook_secret)->toBe($secret)
            ->and($users->pluck('email')->all())->toBe(['original@sunset.test']);
    });
});

describe('tenant:purge', function () {
    it('frees a failed tenant id, dropping row and database', function () {
        $tenant = Tenant::factory()->provisioning()->create(['id' => 'sunsetfest']);
        TenantDatabase::ensureExists($tenant);
        $tenant->update(['status' => TenantStatus::Failed]);

        $this->artisan('tenant:purge', ['tenant_id' => 'sunsetfest'])->assertSuccessful();

        expect(Tenant::query()->find('sunsetfest'))->toBeNull()
            ->and(TenantDatabase::exists($tenant))->toBeFalse();
    });

    it('refuses to purge an active tenant without --force', function () {
        $tenant = provisionTenant('sunsetfest');

        $this->artisan('tenant:purge', ['tenant_id' => 'sunsetfest'])->assertFailed();
        expect(Tenant::query()->find('sunsetfest'))->not->toBeNull()
            ->and(TenantDatabase::exists($tenant))->toBeTrue();

        $this->artisan('tenant:purge', ['tenant_id' => 'sunsetfest', '--force' => true])->assertSuccessful();
        expect(Tenant::query()->find('sunsetfest'))->toBeNull();
    });

    it('fails for an unknown tenant', function () {
        $this->artisan('tenant:purge', ['tenant_id' => 'ghost-org'])->assertFailed();
    });
});
