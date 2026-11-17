<?php

use App\Enums\UserRole;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

function login(string $tenantId, string $email, string $password): TestResponse
{
    return test()->postJson('/api/auth/login', ['email' => $email, 'password' => $password], ['X-Tenant-ID' => $tenantId]);
}

/** @return array{string, Tenant} [token, tenant] */
function loggedInAdmin(string $tenantId = 'acme-events'): array
{
    $tenant = provisionTenant($tenantId, "admin@{$tenantId}.test", 'secret-pass-1');
    $token = login($tenantId, "admin@{$tenantId}.test", 'secret-pass-1')->assertOk()->json('data.token');

    return [$token, $tenant];
}

beforeEach(function () {
    // Probe routes standing in for the admin / door features added in later phases.
    Route::middleware(['api', 'tenant', 'tenant.active', 'auth:sanctum', 'tenant.token'])->group(function () {
        Route::get('/api/_test/me', fn () => response()->json(['email' => request()->user()->email]));
        Route::get('/api/_test/admin', fn () => response()->json(['ok' => true]))->middleware('can:access-admin');
        Route::get('/api/_test/check-in', fn () => response()->json(['ok' => true]))->middleware('can:check-in');
    });
});

describe('login', function () {
    it('issues a bearer token that authenticates against the tenant', function () {
        provisionTenant('acme-events', 'Admin@Acme.test', 'secret-pass-1');

        $response = login('acme-events', 'ADMIN@acme.test', 'secret-pass-1') // email is case-insensitive
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'admin@acme.test')
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonMissingPath('data.user.password');

        expect($response->json('data.expires_at'))->not->toBeNull();

        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$response->json('data.token')])
            ->assertOk()
            ->assertJson(['email' => 'admin@acme.test']);
    });

    it('stores only a hash of the token, in the tenant database, bound to the tenant', function () {
        [$token, $tenant] = loggedInAdmin();

        $row = $tenant->run(fn () => PersonalAccessToken::query()->sole());

        expect($row->token)->not->toBe($token)->and($row->token)->toBe(hash('sha256', explode('|', $token)[1]))
            ->and($row->abilities)->toBe(['tenant:acme-events'])
            ->and($row->expires_at)->not->toBeNull();
        expect(centralTables())->not->toContain('personal_access_tokens'); // not in the central DB
    });

    it('gives the same 401 for a wrong password and an unknown email', function () {
        provisionTenant('acme-events', 'admin@acme.test', 'secret-pass-1');

        $wrong = login('acme-events', 'admin@acme.test', 'nope-nope-1')->assertStatus(401);
        $unknown = login('acme-events', 'nobody@acme.test', 'secret-pass-1')->assertStatus(401);

        expect($wrong->json())->toBe($unknown->json())->and($wrong->json('error'))->toBe('invalid_credentials');
    });

    it('validates the payload', function (array $body, string $field) {
        provisionTenant('acme-events');

        $this->postJson('/api/auth/login', $body, ['X-Tenant-ID' => 'acme-events'])
            ->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
    })->with([
        'no email' => [['password' => 'x'], 'email'],
        'bad email' => [['email' => 'nope', 'password' => 'x'], 'email'],
        'no password' => [['email' => 'a@b.test'], 'password'],
    ]);

    it('applies the tenant identification errors to login', function () {
        $this->postJson('/api/auth/login', ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(400);
        $this->postJson('/api/auth/login', ['email' => 'a@b.test', 'password' => 'x'], ['X-Tenant-ID' => 'ghost-org'])->assertStatus(404);
    });

    it('rejects login for a tenant that is still provisioning or failed (409)', function (string $state) {
        Tenant::factory()->{$state}()->create(['id' => 'acme-events']);

        login('acme-events', 'admin@acme.test', 'secret-pass-1')
            ->assertStatus(409)->assertJsonPath('error', 'tenant_not_ready');
    })->with(['provisioning', 'failed']);

    it('cannot log in with a user that belongs to another tenant', function () {
        provisionTenant('acme-events', 'admin@acme.test', 'secret-pass-1');
        provisionTenant('beta-events', 'admin@beta.test', 'secret-pass-2');

        login('beta-events', 'admin@acme.test', 'secret-pass-1')->assertStatus(401);
        login('acme-events', 'admin@beta.test', 'secret-pass-2')->assertStatus(401);
    });
});

describe('logout', function () {
    it('revokes the token', function () {
        [$token] = loggedInAdmin();
        $headers = ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$token];

        $this->postJson('/api/auth/logout', [], $headers)->assertNoContent();

        // The same bearer token is dead now.
        app('auth')->forgetGuards();
        $this->getJson('/api/_test/me', $headers)->assertStatus(401);
    });

    it('requires authentication', function () {
        provisionTenant('acme-events');

        $this->postJson('/api/auth/logout', [], ['X-Tenant-ID' => 'acme-events'])
            ->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
    });

    it('revokes only the token that was used', function () {
        provisionTenant('acme-events', 'admin@acme.test', 'secret-pass-1');
        $first = login('acme-events', 'admin@acme.test', 'secret-pass-1')->json('data.token');
        $second = login('acme-events', 'admin@acme.test', 'secret-pass-1')->json('data.token');

        $this->postJson('/api/auth/logout', [], ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$first])->assertNoContent();
        app('auth')->forgetGuards();

        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$second])->assertOk();
    });
});

describe('unauthenticated requests', function () {
    it('return a JSON 401 even without an Accept header (no redirect to a login page)', function () {
        provisionTenant('acme-events');

        $this->call('GET', '/api/_test/me', [], [], [], ['HTTP_X-Tenant-ID' => 'acme-events'])
            ->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
    });

    it('reject a garbage token', function () {
        provisionTenant('acme-events');

        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer 1|garbage'])->assertStatus(401);
    });

    it('reject an expired token', function () {
        [$token, $tenant] = loggedInAdmin();
        $tenant->run(fn () => PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]));

        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$token])->assertStatus(401);
    });
});

describe('roles', function () {
    it('lets admins into admin and check-in routes', function () {
        [$token] = loggedInAdmin();
        $headers = ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$token];

        $this->getJson('/api/_test/admin', $headers)->assertOk();
        $this->getJson('/api/_test/check-in', $headers)->assertOk();
    });

    it('lets door_staff check in but keeps them out of admin routes', function () {
        $tenant = provisionTenant('acme-events');
        $tenant->run(fn () => User::factory()->doorStaff()->create(['email' => 'door@acme.test']));

        $token = login('acme-events', 'door@acme.test', 'password')->assertOk()->json('data.token');
        $headers = ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$token];

        $this->getJson('/api/_test/check-in', $headers)->assertOk();
        $this->getJson('/api/_test/admin', $headers)
            ->assertStatus(403)->assertJsonPath('error', 'forbidden');
    });

    it('reports the role on login', function () {
        $tenant = provisionTenant('acme-events');
        $tenant->run(fn () => User::factory()->doorStaff()->create(['email' => 'door@acme.test']));

        login('acme-events', 'door@acme.test', 'password')->assertJsonPath('data.user.role', UserRole::DoorStaff->value);
    });
});

describe('header selects, never grants', function () {
    it("rejects tenant A's token when the header names tenant B", function () {
        [$tokenA] = loggedInAdmin('acme-events');
        provisionTenant('beta-events', 'admin@beta.test', 'secret-pass-2');

        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'beta-events', 'Authorization' => 'Bearer '.$tokenA])
            ->assertStatus(401);
        $this->getJson('/api/_test/admin', ['X-Tenant-ID' => 'beta-events', 'Authorization' => 'Bearer '.$tokenA])
            ->assertStatus(401);
    });

    it("rejects tenant A's token even when tenant B holds a row with the same id", function () {
        [$tokenA, $tenantA] = loggedInAdmin('acme-events');
        $tenantB = provisionTenant('beta-events', 'admin@beta.test', 'secret-pass-2');

        // Force B to hold a token with the SAME id and the SAME hash as A's token (strongest possible attack).
        $row = $tenantA->run(fn () => PersonalAccessToken::query()->sole()->getAttributes());
        $tenantB->run(fn () => DB::table('personal_access_tokens')->insert([
            ...$row, 'tokenable_id' => User::query()->first()->id,
            'abilities' => json_encode(['tenant:acme-events']),
        ]));

        // The row now authenticates in B, but its ability names tenant A, so the tenant guard refuses it.
        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'beta-events', 'Authorization' => 'Bearer '.$tokenA])
            ->assertStatus(401)->assertJsonPath('error', 'invalid_token');
    });

    it('does not accept a token without the tenant ability', function () {
        $tenant = provisionTenant('acme-events');
        $plain = $tenant->run(fn () => User::query()->first()->createToken('legacy', ['*'])->plainTextToken);

        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$plain])
            ->assertStatus(401)->assertJsonPath('error', 'invalid_token');
    });

    it('still works for the right tenant after the cross-tenant attempt', function () {
        [$tokenA] = loggedInAdmin('acme-events');
        provisionTenant('beta-events', 'admin@beta.test', 'secret-pass-2');

        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'beta-events', 'Authorization' => 'Bearer '.$tokenA])->assertStatus(401);
        app('auth')->forgetGuards();
        $this->getJson('/api/_test/me', ['X-Tenant-ID' => 'acme-events', 'Authorization' => 'Bearer '.$tokenA])->assertOk();
    });
});

describe('login rate limit', function () {
    it('throttles repeated attempts for the same email and IP', function () {
        config(['ticketing.rate_limits.login_per_minute' => 3]);
        provisionTenant('acme-events', 'admin@acme.test', 'secret-pass-1');

        foreach (range(1, 3) as $i) {
            login('acme-events', 'admin@acme.test', 'wrong-pass-1')->assertStatus(401);
        }

        login('acme-events', 'admin@acme.test', 'secret-pass-1')
            ->assertStatus(429)->assertJsonPath('error', 'too_many_requests')->assertHeader('Retry-After');
    });

    it("never lets one tenant's failed logins throttle another tenant", function () {
        config(['ticketing.rate_limits.login_per_minute' => 2]);
        provisionTenant('acme-events', 'admin@shared.test', 'secret-pass-1');
        provisionTenant('beta-events', 'admin@shared.test', 'secret-pass-2');

        foreach (range(1, 2) as $i) {
            login('acme-events', 'admin@shared.test', 'wrong-pass-1')->assertStatus(401);
        }
        login('acme-events', 'admin@shared.test', 'secret-pass-1')->assertStatus(429);

        // Same email, same IP, different tenant: separate counter.
        login('beta-events', 'admin@shared.test', 'secret-pass-2')->assertOk();
    });

    it('can be disabled by configuration', function () {
        config(['ticketing.rate_limits.login_per_minute' => 0]);
        provisionTenant('acme-events', 'admin@acme.test', 'secret-pass-1');

        foreach (range(1, 8) as $i) {
            login('acme-events', 'admin@acme.test', 'wrong-pass-1')->assertStatus(401);
        }
    });
});
