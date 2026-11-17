<?php

use App\Actions\Tenancy\RegisterTenant;
use App\Actions\Tenancy\RegisterTenantData;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenantJob;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sunset Fest Sdn Bhd',
        'tenant_id' => 'sunsetfest',
        'currency' => 'MYR',
        'timezone' => 'Asia/Kuala_Lumpur',
        'admin_name' => 'Aisha Rahman',
        'admin_email' => 'Aisha@SunsetFest.test',
        'password' => 'correct-horse-battery',
    ], $overrides);
}

describe('POST /api/register', function () {
    it('registers a tenant, returns 202 and queues provisioning', function () {
        Queue::fake();

        $this->postJson('/api/register', registrationPayload())
            ->assertStatus(202)
            ->assertExactJson(['data' => ['tenant_id' => 'sunsetfest', 'status' => 'provisioning', 'message' => null]]);

        $tenant = Tenant::query()->findOrFail('sunsetfest');
        expect($tenant->status)->toBe(TenantStatus::Provisioning)
            ->and($tenant->currency)->toBe('MYR')
            ->and($tenant->fee_percent_bps)->toBe(500)
            ->and($tenant->fee_fixed_minor)->toBe(50);

        Queue::assertPushed(ProvisionTenantJob::class, 1);
        Queue::assertPushedOn('provisioning', ProvisionTenantJob::class);
    });

    it('never puts the plaintext password in the job payload or the central database', function () {
        Queue::fake();

        $this->postJson('/api/register', registrationPayload())->assertStatus(202);

        Queue::assertPushed(ProvisionTenantJob::class, function (ProvisionTenantJob $job) {
            return $job->passwordHash !== 'correct-horse-battery'
                && Hash::check('correct-horse-battery', $job->passwordHash)
                && ! str_contains(serialize($job), 'correct-horse-battery')
                && $job->adminEmail === 'aisha@sunsetfest.test'; // normalized
        });

        $row = json_encode(DB::table('tenants')->where('id', 'sunsetfest')->first());
        expect($row)->not->toContain('correct-horse-battery');
    });

    it('does not reach the central database through a tenant header', function () {
        Queue::fake();

        $this->postJson('/api/register', registrationPayload(), ['X-Tenant-ID' => 'whatever'])
            ->assertStatus(202);

        expect(tenancy()->initialized)->toBeFalse();
    });

    it('rejects an existing tenant id with 422 and never touches that tenant', function () {
        Queue::fake();
        $existing = Tenant::factory()->create(['id' => 'sunsetfest', 'name' => 'Original Name']);
        $before = DB::table('tenants')->where('id', 'sunsetfest')->first();

        $this->postJson('/api/register', registrationPayload(['name' => 'Hijacked', 'currency' => 'SGD']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonValidationErrors('tenant_id', 'errors');

        expect(DB::table('tenants')->where('id', 'sunsetfest')->first())->toEqual($before)
            ->and(Tenant::query()->count())->toBe(1);
        Queue::assertNothingPushed(); // no job: no new admin, no password change
    });

    it('rejects a failed tenant id (not reusable via sign-up)', function () {
        Queue::fake();
        Tenant::factory()->failed()->create(['id' => 'sunsetfest']);

        $this->postJson('/api/register', registrationPayload())->assertStatus(422);

        Queue::assertNothingPushed();
    });

    it('gives a clean 422, not a 500, when two sign-ups race for the same id', function () {
        // Both pass validation (id was free), the second INSERT then loses on the PRIMARY KEY.
        $data = new RegisterTenantData('sunsetfest', 'A', 'MYR', 'UTC', 500, 50, 'Admin', 'a@a.test', Hash::make('x'));

        app(RegisterTenant::class)->create($data);

        expect(fn () => app(RegisterTenant::class)->create($data))
            ->toThrow(ValidationException::class);
        expect(Tenant::query()->count())->toBe(1);
    });

    it('rejects reserved ids', function (string $id) {
        $this->postJson('/api/register', registrationPayload(['tenant_id' => $id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant_id', 'errors');
    })->with(['api', 'admin', 'www', 'register', 'central']);

    it('rejects invalid ids', function (string $id) {
        $this->postJson('/api/register', registrationPayload(['tenant_id' => $id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant_id', 'errors');
    })->with([
        'too short' => 'ab',
        'too long' => str_repeat('a', 31),
        'uppercase' => 'SunsetFest',
        'underscore' => 'sunset_fest',
        'leading hyphen' => '-sunset',
        'trailing hyphen' => 'sunset-',
        'space' => 'sunset fest',
        'dot' => 'sunset.fest',
        'slash' => 'sun/set',
    ]);

    it('accepts boundary ids', function (string $id) {
        Queue::fake();

        $this->postJson('/api/register', registrationPayload(['tenant_id' => $id]))->assertStatus(202);
    })->with(['3 chars' => 'abc', '30 chars' => str_repeat('a', 30), 'hyphens and digits' => 'a1-b2-c3']);

    it('validates the remaining fields', function (array $override, string $field) {
        $this->postJson('/api/register', registrationPayload($override))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field, 'errors');
    })->with([
        'unsupported currency' => [['currency' => 'XXX'], 'currency'],
        'lowercase currency' => [['currency' => 'myr'], 'currency'],
        'bad timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
        'bad email' => [['admin_email' => 'not-an-email'], 'admin_email'],
        'short password' => [['password' => 'short'], 'password'],
        'missing name' => [['name' => ''], 'name'],
        'missing admin name' => [['admin_name' => ''], 'admin_name'],
    ]);

    it('is rate limited per IP', function () {
        Queue::fake();
        config(['ticketing.rate_limits.register_per_minute' => 3]);

        // Invalid bodies still count: the limiter protects the expensive path.
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/register', [])->assertStatus(422);
        }

        $this->postJson('/api/register', registrationPayload())
            ->assertStatus(429)
            ->assertJsonPath('error', 'too_many_requests')
            ->assertHeader('Retry-After');

        expect(Tenant::query()->count())->toBe(0);
    });
});

describe('GET /api/register/{tenant_id}', function () {
    it('reports provisioning, active and failed', function (string $state, string $expected) {
        Tenant::factory()->{$state}()->create(['id' => 'sunsetfest']);

        $this->getJson('/api/register/sunsetfest')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 'sunsetfest')
            ->assertJsonPath('data.status', $expected);
    })->with([['provisioning', 'provisioning'], ['failed', 'failed']]);

    it('reports active', function () {
        Tenant::factory()->create(['id' => 'sunsetfest']);

        $this->getJson('/api/register/sunsetfest')->assertOk()->assertJsonPath('data.status', 'active');
    });

    it('does not leak internal failure details', function () {
        Tenant::factory()->failed()->create(['id' => 'sunsetfest', 'failure_reason' => 'SQLSTATE[08006] secret internals']);

        $response = $this->getJson('/api/register/sunsetfest')->assertOk();

        expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('failure_reason');
    });

    it('returns 404 for an unknown tenant', function () {
        $this->getJson('/api/register/ghost-org')->assertStatus(404)->assertJsonPath('error', 'not_found');
    });
});
