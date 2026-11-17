<?php

use App\Http\Middleware\IdentifyTenantByHeader;
use App\Models\Central\Tenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'tenant', 'tenant.active'])
        ->get('/api/_test/whoami', fn () => response()->json(['tenant' => tenant('id')]));

    Route::middleware(['api', 'tenant.path', 'tenant.active'])
        ->get('/api/_test/tenants/{tenant}/ping', fn (...$args) => response()->json([
            'tenant' => tenant('id'),
            'controller_args' => count($args), // {tenant} must not leak into the controller
        ]));
});

describe('X-Tenant-ID header', function () {
    it('returns 400 as JSON when the header is missing', function () {
        $this->getJson('/api/_test/whoami')
            ->assertStatus(400)
            ->assertJson(['error' => 'tenant_header_missing']);
    });

    it('returns 404 as JSON for an unknown tenant', function () {
        $this->getJson('/api/_test/whoami', ['X-Tenant-ID' => 'nope-nope'])
            ->assertStatus(404)
            ->assertJson(['error' => 'tenant_not_found']);
    });

    it('does not accept the tenant from the query string', function () {
        $tenant = Tenant::factory()->create();

        foreach (['tenant', 'X-Tenant-ID', 'X-Tenant', 'tenant_id'] as $param) {
            $this->getJson('/api/_test/whoami?'.$param.'='.$tenant->id)
                ->assertStatus(400)
                ->assertJson(['error' => 'tenant_header_missing']);
        }
    });

    it('initializes the tenant named by the header', function () {
        $tenant = Tenant::factory()->create();

        $this->getJson('/api/_test/whoami', ['X-Tenant-ID' => $tenant->id])
            ->assertOk()
            ->assertJson(['tenant' => $tenant->id]);
    });

    it('ignores a tenant id in the query string even when a different header is sent', function () {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->getJson('/api/_test/whoami?tenant='.$b->id, ['X-Tenant-ID' => $a->id])
            ->assertOk()
            ->assertJson(['tenant' => $a->id]);
    });

    it('returns 409 tenant_not_ready while provisioning', function () {
        $tenant = Tenant::factory()->provisioning()->create();

        $this->getJson('/api/_test/whoami', ['X-Tenant-ID' => $tenant->id])
            ->assertStatus(409)
            ->assertJson(['error' => 'tenant_not_ready', 'status' => 'provisioning']);

        expect(tenancy()->initialized)->toBeFalse();
    });

    it('returns 409 tenant_not_ready for a failed tenant', function () {
        $tenant = Tenant::factory()->failed()->create();

        $this->getJson('/api/_test/whoami', ['X-Tenant-ID' => $tenant->id])
            ->assertStatus(409)
            ->assertJson(['error' => 'tenant_not_ready', 'status' => 'failed']);
    });
});

describe('tenant in the URL path', function () {
    it('initializes the tenant and keeps {tenant} out of the controller', function () {
        $tenant = Tenant::factory()->create();

        $this->getJson("/api/_test/tenants/{$tenant->id}/ping")
            ->assertOk()
            ->assertJson(['tenant' => $tenant->id, 'controller_args' => 0]);
    });

    it('returns 404 for an unknown tenant', function () {
        $this->getJson('/api/_test/tenants/ghost-org/ping')
            ->assertStatus(404)
            ->assertJson(['error' => 'tenant_not_found']);
    });

    it('returns 409 for a tenant that is not ready', function () {
        $tenant = Tenant::factory()->provisioning()->create();

        $this->getJson("/api/_test/tenants/{$tenant->id}/ping")
            ->assertStatus(409)
            ->assertJson(['error' => 'tenant_not_ready']);
    });
});

describe('middleware ordering', function () {
    it('runs tenant identification before authentication', function () {
        $priority = app(Kernel::class)->getMiddlewarePriority();

        $identify = array_search(IdentifyTenantByHeader::class, $priority, true);
        $auth = array_search(AuthenticatesRequests::class, $priority, true);

        expect($identify)->not->toBeFalse()
            ->and($auth)->not->toBeFalse()
            ->and($identify)->toBeLessThan($auth);
    });
});

describe('tenant model', function () {
    it('maps hyphenated ids to collision-free database names', function () {
        $tenant = Tenant::factory()->make(['id' => 'sunset-fest']);

        expect($tenant->database()->getName())->toBe(config('tenancy.database.prefix').'sunset_fest');
    });

    it('stores fees as integer minor units and encrypts the webhook secret', function () {
        $tenant = Tenant::factory()->create(['webhook_secret' => 'abc123']);

        $raw = DB::table('tenants')->where('id', $tenant->id)->first();

        expect($tenant->fresh()->webhook_secret)->toBe('abc123')
            ->and($raw->webhook_secret)->not->toBe('abc123')
            ->and($tenant->fresh()->fee_percent_bps)->toBeInt();
    });

    it('rejects an invalid status and a malformed id at the database level', function () {
        expect(fn () => DB::table('tenants')->insert([
            'id' => 'bad_id', 'name' => 'x', 'status' => 'active', 'currency' => 'MYR',
            'timezone' => 'UTC', 'fee_percent_bps' => 0, 'fee_fixed_minor' => 0,
        ]))->toThrow(QueryException::class);

        expect(fn () => DB::table('tenants')->insert([
            'id' => 'good-id', 'name' => 'x', 'status' => 'bogus', 'currency' => 'MYR',
            'timezone' => 'UTC', 'fee_percent_bps' => 0, 'fee_fixed_minor' => 0,
        ]))->toThrow(QueryException::class);
    });
});

describe('JSON errors', function () {
    it('returns a JSON 404 for unknown routes', function () {
        $this->get('/api/does-not-exist')
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found']);
    });

    it('returns a JSON 405 for the wrong method', function () {
        $this->postJson('/api/_test/whoami')
            ->assertStatus(405)
            ->assertJson(['error' => 'method_not_allowed']);
    });
});
