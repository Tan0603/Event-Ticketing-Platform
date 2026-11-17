<?php

use App\Actions\Orders\ExpirePendingOrders;
use App\Actions\Orders\QueueEventReminders;
use App\Actions\Orders\ReleaseOrder;
use App\Console\DispatchForActiveTenants;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Jobs\ExpirePendingOrdersJob;
use App\Jobs\QueueEventRemindersJob;
use App\Mail\EventReminderMail;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\TicketType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 10:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->pub = ['X-Tenant-ID' => 'acme-events'];

    [$this->event, $this->type] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'title' => 'Sunset Fest', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);

        return [$event, TicketType::factory()->for($event)->create(['name' => 'General', 'price_minor' => 5000, 'quantity' => 10, 'max_per_order' => 10])];
    });

    $this->buy = fn (int $quantity, string $email = 'a@example.com') => $this->postJson('/api/events/sunset/checkout', [
        'buyer_name' => 'Aisha', 'buyer_email' => $email, 'items' => [['ticket_type_id' => $this->type->id, 'quantity' => $quantity]],
    ], $this->pub)->assertCreated()->json('data.reference');

    $this->stock = fn () => $this->tenant->run(fn () => TicketType::query()->find($this->type->id, ['sold', 'held', 'quantity'])->only(['sold', 'held']));
    $this->status = fn (string $reference) => $this->tenant->run(fn () => Order::query()->where('reference', $reference)->value('status'));
    $this->expire = fn () => $this->tenant->run(fn () => app(ExpirePendingOrders::class)->execute());
});

describe('expiring pending orders', function () {
    it('releases the held tickets of an order whose hold ran out', function () {
        $reference = ($this->buy)(3);
        expect(($this->stock)())->toBe(['sold' => 0, 'held' => 3]);

        Carbon::setTestNow(now()->addMinutes(config('ticketing.hold_minutes') + 1));

        expect(($this->expire)())->toBe(1)
            ->and(($this->status)($reference))->toBe(OrderStatus::Expired)
            ->and(($this->stock)())->toBe(['sold' => 0, 'held' => 0]);
    });

    it('leaves orders that are still inside their hold alone', function () {
        $reference = ($this->buy)(2);
        Carbon::setTestNow(now()->addMinutes(config('ticketing.hold_minutes') - 1));

        expect(($this->expire)())->toBe(0)
            ->and(($this->status)($reference))->toBe(OrderStatus::Pending)
            ->and(($this->stock)())->toBe(['sold' => 0, 'held' => 2]);
    });

    it('is idempotent: a second run changes nothing and never over-releases', function () {
        ($this->buy)(2);
        ($this->buy)(1, 'b@example.com');
        Carbon::setTestNow(now()->addHour());

        expect(($this->expire)())->toBe(2)
            ->and(($this->expire)())->toBe(0)
            ->and(($this->stock)())->toBe(['sold' => 0, 'held' => 0]);
    });

    it('treats a payment that arrives after expires_at but before the task ran as a normal payment', function () {
        $reference = ($this->buy)(2);
        Carbon::setTestNow(now()->addMinutes(config('ticketing.hold_minutes') + 1));

        $body = json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($reference), 'amount' => 10600, 'currency' => 'MYR']);
        $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
        ], $body)->assertOk()->assertJson(['status' => 'paid']);

        expect(($this->expire)())->toBe(0) // nothing left to expire
            ->and(($this->status)($reference))->toBe(OrderStatus::Paid)
            ->and(($this->stock)())->toBe(['sold' => 2, 'held' => 0]);
    });

    it('never expires a paid order, whatever its expires_at says', function () {
        $reference = ($this->buy)(1);
        $this->tenant->run(fn () => DB::table('orders')->where('reference', $reference)->update(['status' => OrderStatus::Paid->value, 'paid_at' => now()]));
        Carbon::setTestNow(now()->addDay());

        expect(($this->expire)())->toBe(0)->and(($this->status)($reference))->toBe(OrderStatus::Paid);
    });

    it('keeps going when one order cannot be released, and retries it on the next run', function () {
        $first = ($this->buy)(1);
        $second = ($this->buy)(2, 'b@example.com');
        Carbon::setTestNow(now()->addHour());

        $state = new stdClass;
        $state->broken = true;
        $this->app->bind(ReleaseOrder::class, fn () => $state->broken
            ? new class extends ReleaseOrder
            {
                public function execute(Order $order, OrderStatus $to): void
                {
                    if ($order->items->first()->quantity === 1) {
                        throw new RuntimeException('deadlock detected');
                    }
                    parent::execute($order, $to);
                }
            }
            : new ReleaseOrder);

        expect(($this->expire)())->toBe(1)
            ->and(($this->status)($first))->toBe(OrderStatus::Pending) // rolled back, hold intact
            ->and(($this->status)($second))->toBe(OrderStatus::Expired)
            ->and(($this->stock)())->toBe(['sold' => 0, 'held' => 1]);

        $state->broken = false;
        expect(($this->expire)())->toBe(1)->and(($this->stock)())->toBe(['sold' => 0, 'held' => 0]);
    });

    it('works through more orders than one batch', function () {
        config(['ticketing.expiry_batch_size' => 2]);
        foreach (range(1, 5) as $i) {
            ($this->buy)(1, "buyer{$i}@example.com");
        }
        Carbon::setTestNow(now()->addHour());

        expect(($this->expire)())->toBe(5)->and(($this->stock)())->toBe(['sold' => 0, 'held' => 0]);
    });

    it('makes the freed tickets available to the next buyer straight away', function () {
        ($this->buy)(10); // sold out of stock
        $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'B', 'buyer_email' => 'b@example.com', 'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
        ], $this->pub)->assertStatus(409)->assertJsonPath('error', 'insufficient_stock');

        Carbon::setTestNow(now()->addHour());
        ($this->expire)();

        ($this->buy)(10, 'c@example.com');
    });
});

describe('fan-out to tenants', function () {
    it('dispatches exactly one job per ACTIVE tenant', function () {
        provisionTenant('other-org', 'admin@other.test');
        provisionTenant('stuck-org', 'admin@stuck.test');
        Tenant::query()->where('id', 'stuck-org')->update(['status' => TenantStatus::Provisioning->value]);
        Queue::fake();

        (new DispatchForActiveTenants(ExpirePendingOrdersJob::class))();

        Queue::assertPushed(ExpirePendingOrdersJob::class, 2);
        Queue::assertPushed(ExpirePendingOrdersJob::class, fn ($job) => $job->tenantId === 'acme-events');
        Queue::assertPushed(ExpirePendingOrdersJob::class, fn ($job) => $job->tenantId === 'other-org');
        Queue::assertNotPushed(ExpirePendingOrdersJob::class, fn ($job) => $job->tenantId === 'stuck-org');
    });

    it('registers both tasks with the scheduler, each without overlapping', function () {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);

        expect($events)->toHaveKeys(['expire-pending-orders', 'queue-event-reminders'])
            ->and($events['expire-pending-orders']->expression)->toBe('* * * * *')
            ->and($events['queue-event-reminders']->expression)->toBe('0 * * * *') // hourly (spec section 15)
            ->and($events['expire-pending-orders']->withoutOverlapping)->toBeTrue()
            ->and($events['queue-event-reminders']->withoutOverlapping)->toBeTrue();
    });

    it('runs a tenant job inside its own tenant only', function () {
        $other = provisionTenant('other-org', 'admin@other.test');
        $otherEvent = $other->run(fn () => Event::factory()->published()->create(['slug' => 'sunset']));
        $otherType = $other->run(fn () => TicketType::factory()->for($otherEvent)->create(['quantity' => 5, 'max_per_order' => 5]));
        $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'B', 'buyer_email' => 'b@example.com', 'items' => [['ticket_type_id' => $otherType->id, 'quantity' => 2]],
        ], ['X-Tenant-ID' => 'other-org'])->assertCreated();
        $mine = ($this->buy)(1);
        Carbon::setTestNow(now()->addHour());

        tenancy()->end(); // a worker has no tenant
        (new ExpirePendingOrdersJob('acme-events'))->handle(app(ExpirePendingOrders::class));

        expect(($this->status)($mine))->toBe(OrderStatus::Expired)
            ->and($other->run(fn () => Order::query()->sole()->status))->toBe(OrderStatus::Pending)
            ->and($other->run(fn () => TicketType::query()->find($otherType->id)->held))->toBe(2);
    });
});

describe('event reminders', function () {
    beforeEach(function () {
        $this->make = function (string $startsAt, array $event = [], string $status = 'paid', string $email = 'buyer@example.com') {
            return $this->tenant->run(function () use ($startsAt, $event, $status, $email) {
                $e = Event::factory()->published()->create($event + ['starts_at' => $startsAt, 'ends_at' => Carbon::parse($startsAt)->addHours(3)]);

                return Order::factory()->state(['event_id' => $e->id, 'buyer_email' => $email, 'status' => OrderStatus::from($status), 'paid_at' => $status === 'paid' ? now() : null])->create();
            });
        };
        $this->queueReminders = fn () => $this->tenant->run(fn () => app(QueueEventReminders::class)->execute());
        $this->notifications = fn () => $this->tenant->run(fn () => DB::table('order_notifications')->where('type', 'reminder')->count());
    });

    it('reminds paid buyers of events starting within the window, and nobody else', function () {
        Queue::fake();
        ($this->make)('2027-01-02 08:00:00', email: 'soon@example.com');                                   // 22 h away: yes
        ($this->make)('2027-01-03 12:00:00', email: 'later@example.com');                                  // 50 h away: not yet
        ($this->make)('2026-12-31 08:00:00', email: 'past@example.com');                                   // already started
        ($this->make)('2027-01-02 08:00:00', ['status' => EventStatus::Cancelled], email: 'cancelled@example.com');
        ($this->make)('2027-01-02 08:00:00', status: 'pending', email: 'unpaid@example.com');
        ($this->make)('2027-01-02 08:00:00', status: 'expired', email: 'expired@example.com');

        expect(($this->queueReminders)())->toBe(1)->and(($this->notifications)())->toBe(1);
    });

    it('sends each buyer one reminder however often the task runs', function () {
        Mail::fake();
        ($this->make)('2027-01-02 08:00:00');

        expect(($this->queueReminders)())->toBe(1)
            ->and(($this->queueReminders)())->toBe(0);
        Carbon::setTestNow(now()->addHours(2));
        expect(($this->queueReminders)())->toBe(0);

        Mail::assertSent(EventReminderMail::class, 1);
        Mail::assertSent(EventReminderMail::class, function (EventReminderMail $mail) {
            $this->tenant->run(function () use ($mail) {
                $mail->assertTo('buyer@example.com');
                $mail->assertSeeInHtml('is coming up');
                $mail->assertSeeInHtml('/api/public/acme-events/orders/');
            });

            return true;
        });
    });

    it('picks the event up as it enters the window', function () {
        Queue::fake();
        ($this->make)('2027-01-02 14:00:00'); // 28 h away at 10:00

        expect(($this->queueReminders)())->toBe(0);
        Carbon::setTestNow('2027-01-01 15:00:00'); // 23 h away
        expect(($this->queueReminders)())->toBe(1);
    });

    it('is a per-tenant job that runs inside its tenant', function () {
        Mail::fake();
        ($this->make)('2027-01-02 08:00:00');
        tenancy()->end();

        (new QueueEventRemindersJob('acme-events'))->handle(app(QueueEventReminders::class));

        Mail::assertSent(EventReminderMail::class, 1);
    });
});
