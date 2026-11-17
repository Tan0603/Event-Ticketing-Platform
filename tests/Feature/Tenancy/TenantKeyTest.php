<?php

use App\Models\Central\Tenant;
use App\Support\TenantKey;

it('namespaces keys by tenant id', function () {
    expect(TenantKey::forTenant('sunsetfest', 'checkout-rl', 'ip', '1.2.3.4'))
        ->toBe('t:sunsetfest:checkout-rl:ip:1.2.3.4');
});

it('namespaces central keys separately from tenant keys', function () {
    expect(TenantKey::central('register', 'ip', '1.2.3.4'))->toBe('c:register:ip:1.2.3.4');
});

it('uses the initialized tenant', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);

    expect(TenantKey::for('lock', 'x'))->toBe("t:{$tenant->id}:lock:x");
});

it('fails closed outside tenant context', function () {
    expect(fn () => TenantKey::for('lock'))->toThrow(LogicException::class);
});

it('gives different tenants different keys', function () {
    expect(TenantKey::forTenant('a-org', 'k'))->not->toBe(TenantKey::forTenant('b-org', 'k'));
});
