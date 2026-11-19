<?php

use App\Actions\Exports\GenerateAttendeeExport;
use App\Actions\Orders\ExpirePendingOrders;
use App\Actions\Tenancy\RegisterTenant;
use App\Actions\Tenancy\RegisterTenantData;
use App\Console\DispatchForActiveTenants;
use App\Enums\ExportStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Exceptions\TenantDatabaseMissing;
use App\Jobs\ExpirePendingOrdersJob;
use App\Jobs\GenerateAttendeeExportJob;
use App\Jobs\SendOrderEmailJob;
use App\Models\Central\Tenant;
use App\Models\Tenant\AttendeeExport;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\User;
use App\Support\TenantDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** An ACTIVE central tenant row whose database does not exist (dropped by hand, restored backup, ...). */
function activeTenantWithoutDatabase(string $id = 'ghost-org'): Tenant
{
    $tenant = app(RegisterTenant::class)->create(new RegisterTenantData(
        id: $id, name: 'Ghost Org', currency: 'MYR', timezone: 'Asia/Kuala_Lumpur', feePercentBps: 500, feeFixedMinor: 50,
        adminName: 'Ghost Admin', adminEmail: 'admin@ghost.test', passwordHash: Hash::make('secret-pass-1'),
    ));
    Tenant::query()->whereKey($id)->update(['status' => TenantStatus::Active->value]);

    expect(TenantDatabase::exists($tenant))->toBeFalse();

    return $tenant;
}

/** An export row without going through the queue (the caller is inside the tenant). */
function exportRow(Event $event, ExportStatus $status): int
{
    $export = new AttendeeExport;
    $export->forceFill(['event_id' => $event->id, 'requested_by' => User::query()->value('id'), 'status' => $status])->save();

    return $export->id;
}

describe('scheduler fan-out', function () {
    it('skips an active tenant without a database and still dispatches for healthy tenants', function () {
        provisionTenant('acme-events');
        activeTenantWithoutDatabase();
        Queue::fake();

        (new DispatchForActiveTenants(ExpirePendingOrdersJob::class))();

        Queue::assertPushed(ExpirePendingOrdersJob::class, 1);
        Queue::assertPushed(ExpirePendingOrdersJob::class, fn ($job) => $job->tenantId === 'acme-events');
        Queue::assertNotPushed(ExpirePendingOrdersJob::class, fn ($job) => $job->tenantId === 'ghost-org');
    });
});

describe('a tenant job for a missing database', function () {
    it('throws the dedicated exception and leaves the process usable for the next tenant (no poisoned worker)', function () {
        $healthy = provisionTenant('acme-events');
        activeTenantWithoutDatabase();

        $orderId = $healthy->run(function () {
            $event = Event::factory()->published()->create();

            return Order::factory()->create(['event_id' => $event->id, 'status' => OrderStatus::Pending, 'expires_at' => now()->subHour()])->id;
        });
        tenancy()->end(); // a worker has no tenant between jobs

        expect(fn () => (new ExpirePendingOrdersJob('ghost-org'))->handle(app(ExpirePendingOrders::class)))
            ->toThrow(TenantDatabaseMissing::class, 'ghost-org');

        // The poisoning: cache set to null, filesystem revert throwing, tenancy half initialised.
        expect(tenancy()->initialized)->toBeFalse()
            ->and(app('cache'))->not->toBeNull()
            ->and(cache()->store()->put('probe', 'ok', 60))->toBeTrue()
            ->and(cache()->get('probe'))->toBe('ok');

        // ... and the very next job of a healthy tenant works in the same process.
        (new ExpirePendingOrdersJob('acme-events'))->handle(app(ExpirePendingOrders::class));

        expect($healthy->run(fn () => Order::query()->whereKey($orderId)->value('status')))->toBe(OrderStatus::Expired);
    });

    it('lets a healthy tenant export reach ready right after a missing-database job failed', function () {
        Storage::fake('exports');
        $healthy = provisionTenant('acme-events');
        activeTenantWithoutDatabase();

        $exportId = $healthy->run(function () {
            $event = Event::factory()->published()->create();

            return exportRow($event, ExportStatus::Pending);
        });
        tenancy()->end();

        expect(fn () => (new GenerateAttendeeExportJob('ghost-org', 1))->handle(app(GenerateAttendeeExport::class)))
            ->toThrow(TenantDatabaseMissing::class);

        (new GenerateAttendeeExportJob('acme-events', $exportId))->handle(app(GenerateAttendeeExport::class));

        expect($healthy->run(fn () => AttendeeExport::query()->whereKey($exportId)->value('status')))->toBe(ExportStatus::Ready);
    });

    it('does not throw from failed() handlers', function () {
        activeTenantWithoutDatabase();
        $error = new RuntimeException('boom');

        (new GenerateAttendeeExportJob('ghost-org', 1))->failed($error);
        (new SendOrderEmailJob('ghost-org', 1, NotificationType::Confirmation))->failed($error);

        expect(tenancy()->initialized)->toBeFalse()->and(app('cache'))->not->toBeNull();
    });

    it('still marks the export failed for a healthy tenant', function () {
        $healthy = provisionTenant('acme-events');
        $exportId = $healthy->run(function () {
            $event = Event::factory()->published()->create();

            return exportRow($event, ExportStatus::Processing);
        });
        tenancy()->end();

        (new GenerateAttendeeExportJob('acme-events', $exportId))->failed(new RuntimeException('timed out'));

        expect($healthy->run(fn () => AttendeeExport::query()->whereKey($exportId)->value('status')))->toBe(ExportStatus::Failed);
    });
});
