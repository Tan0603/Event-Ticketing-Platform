<?php

it('holds only tenants (and failed jobs) in the central database', function () {
    // Everything else (users, tokens, events, orders ...) belongs to the tenant databases.
    expect(centralTables())->toBe(['failed_jobs', 'migrations', 'tenants']);
});

it('still holds only tenants after a tenant has been provisioned and used', function () {
    provisionTenant('acme-events');

    expect(centralTables())->toBe(['failed_jobs', 'migrations', 'tenants']);
});
