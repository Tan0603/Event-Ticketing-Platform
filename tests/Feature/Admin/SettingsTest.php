<?php

use App\Enums\UserRole;
use App\Models\Central\Tenant;

it('shows an admin the webhook url and secret', function () {
    $tenant = provisionTenant('acme-events');

    $response = $this->getJson('/api/admin/settings', tenantHeaders($tenant))->assertOk()
        ->assertJsonPath('data.tenant_id', 'acme-events')
        ->assertJsonPath('data.currency', 'MYR')
        ->assertJsonPath('data.fee_percent_bps', 500)
        ->assertJsonPath('data.payments.webhook_url', url('/api/webhooks/payments/acme-events'));

    // the secret shown is the one the webhook endpoint verifies with
    expect($response->json('data.payments.webhook_secret'))->toBe(Tenant::query()->find('acme-events')->webhook_secret)->not->toBeEmpty();
});

it('is for admins only', function () {
    $tenant = provisionTenant('acme-events');

    $this->getJson('/api/admin/settings', tenantHeaders($tenant, UserRole::DoorStaff))->assertForbidden();
    $this->getJson('/api/admin/settings', ['X-Tenant-ID' => 'acme-events'])->assertUnauthorized();
});

it('never shows another tenant\'s secret', function () {
    $a = provisionTenant('acme-events');
    $b = provisionTenant('other-org', 'admin@other.test');

    $secretB = $this->getJson('/api/admin/settings', tenantHeaders($b))->json('data.payments.webhook_secret');
    $secretA = $this->getJson('/api/admin/settings', tenantHeaders($a))->json('data.payments.webhook_secret');

    expect($secretA)->not->toBe($secretB);
});
