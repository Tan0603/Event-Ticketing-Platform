<?php

use App\Actions\Orders\QueueOrderEmail;
use App\Domain\Money\MoneyFormatter;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Jobs\SendOrderEmailJob;
use App\Mail\OrderConfirmationMail;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use App\Models\Tenant\TicketType;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Testing\Fakes\MailFake;

function pay(string $tenantId, string $reference, int $total, string $eventId): void
{
    $body = json_encode(['id' => $eventId, 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($reference, $tenantId), 'amount' => $total, 'currency' => 'MYR']);
    test()->call('POST', "/api/webhooks/payments/{$tenantId}", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find($tenantId)->webhook_secret),
    ], $body)->assertOk();
}

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->pub = ['X-Tenant-ID' => 'acme-events'];

    [$this->event, $this->vip, $this->general] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'title' => 'Sunset Fest', 'venue' => 'Main Hall', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);

        return [
            $event,
            TicketType::factory()->for($event)->create(['name' => 'VIP', 'price_minor' => 12000, 'quantity' => 10, 'max_per_order' => 5, 'sort_order' => 1]),
            TicketType::factory()->for($event)->create(['name' => 'General', 'price_minor' => 5000, 'quantity' => 100, 'max_per_order' => 10, 'sort_order' => 2]),
        ];
    });

    // 1 x VIP + 2 x General: subtotal 22000, fee 1150, total 23150
    $this->buy = function () {
        $r = $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'Aisha Rahman', 'buyer_email' => 'aisha@example.com',
            'items' => [['ticket_type_id' => $this->vip->id, 'quantity' => 1], ['ticket_type_id' => $this->general->id, 'quantity' => 2]],
        ], $this->pub)->assertCreated();

        return [$r->json('data.reference'), $r->json('data.total_minor')];
    };
    $this->tickets = fn () => $this->tenant->run(fn () => Ticket::query()->orderBy('id')->get());
});

describe('issuing tickets', function () {
    it('creates one valid ticket per seat only once the order is paid', function () {
        Mail::fake();
        [$reference, $total] = ($this->buy)();
        expect(($this->tickets)())->toHaveCount(0); // a pending order has none

        pay('acme-events', $reference, $total, 'evt_1');

        $tickets = ($this->tickets)();
        expect($tickets)->toHaveCount(3)
            ->and($tickets->pluck('status')->map->value->unique()->all())->toBe(['valid'])
            ->and($tickets->pluck('code')->unique())->toHaveCount(3)
            ->and($tickets->pluck('code')->every(fn ($code) => preg_match('/^[A-Z0-9]{20}$/', $code) === 1))->toBeTrue()
            ->and($tickets->where('ticket_type_id', $this->general->id)->pluck('seq')->sort()->values()->all())->toBe([1, 2])
            ->and($tickets->pluck('event_id')->unique()->all())->toBe([$this->event->id]);
    });

    it('does not create a second set when the payment is notified twice', function () {
        Mail::fake();
        [$reference, $total] = ($this->buy)();

        pay('acme-events', $reference, $total, 'evt_1');
        pay('acme-events', $reference, $total, 'evt_1'); // duplicate event id
        pay('acme-events', $reference, $total, 'evt_2'); // second success under a new id

        expect(($this->tickets)())->toHaveCount(3);
    });

    it('is enforced by the database: one ticket 1 per order line, and used means checked in', function () {
        Mail::fake();
        [$reference, $total] = ($this->buy)();
        pay('acme-events', $reference, $total, 'evt_1');

        $this->tenant->run(function () {
            $first = Ticket::query()->first();

            expect(fn () => DB::table('tickets')->insert(['order_id' => $first->order_id, 'order_item_id' => $first->order_item_id, 'ticket_type_id' => $first->ticket_type_id, 'event_id' => $first->event_id, 'seq' => $first->seq, 'code' => 'ANOTHERCODE00000000X', 'status' => 'valid', 'created_at' => now(), 'updated_at' => now()]))
                ->toThrow(UniqueConstraintViolationException::class);
            expect(fn () => DB::statement("UPDATE tickets SET status = 'used' WHERE id = ?", [$first->id]))->toThrow(QueryException::class);
        });
    });

    it('paid free orders get tickets and an e-mail too', function () {
        Mail::fake();
        $free = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['name' => 'Free', 'price_minor' => 0, 'quantity' => 5]));

        $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'Aisha', 'buyer_email' => 'aisha@example.com', 'items' => [['ticket_type_id' => $free->id, 'quantity' => 2]],
        ], $this->pub)->assertCreated()->assertJsonPath('data.status', 'paid');

        expect(($this->tickets)())->toHaveCount(2);
        Mail::assertSent(OrderConfirmationMail::class, 1);
    });
});

describe('confirmation e-mail', function () {
    it('is sent once to the buyer after payment, with the order, the tickets and QR codes', function () {
        Mail::fake();
        [$reference, $total] = ($this->buy)();
        Mail::assertNothingSent(); // nothing before payment

        pay('acme-events', $reference, $total, 'evt_1');

        $codes = ($this->tickets)()->pluck('code');
        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($reference, $codes) {
            $this->tenant->run(function () use ($mail, $reference, $codes) {
                $mail->assertTo('aisha@example.com');
                $mail->assertHasSubject("Your tickets for Sunset Fest ({$reference})");
                $mail->assertSeeInHtml('Hi Aisha Rahman');
                $mail->assertSeeInHtml($reference);
                $mail->assertSeeInHtml('Main Hall');
                $mail->assertSeeInHtml('MYR 232.50'); // total paid
                $mail->assertSeeInHtml('MYR 12.50');  // fees
                $mail->assertSeeInHtml('2 &times; General', false);
                foreach ($codes as $code) {
                    $mail->assertSeeInHtml($code);
                }
                $mail->assertSeeInHtml('/api/public/acme-events/orders/'.$reference.'/tickets?expires=');
            });

            return true;
        });
        Mail::assertSent(OrderConfirmationMail::class, 1);

        $row = $this->tenant->run(fn () => DB::table('order_notifications')->sole());
        expect($row->type)->toBe('confirmation')->and($row->status)->toBe('sent')->and($row->attempts)->toBe(1)->and($row->sent_at)->not->toBeNull();
    });

    it('is not sent again for a duplicate or repeated payment notification', function () {
        Mail::fake();
        [$reference, $total] = ($this->buy)();

        pay('acme-events', $reference, $total, 'evt_1');
        pay('acme-events', $reference, $total, 'evt_1');
        pay('acme-events', $reference, $total, 'evt_2');

        Mail::assertSent(OrderConfirmationMail::class, 1);
        expect($this->tenant->run(fn () => DB::table('order_notifications')->count()))->toBe(1);
    });

    it('is not sent for a payment that failed', function () {
        Mail::fake();
        [$reference] = ($this->buy)();
        $body = json_encode(['id' => 'evt_f', 'type' => 'payment.failed', 'payment_reference' => paymentRef($reference)]);
        $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret)], $body)->assertOk();

        Mail::assertNothingSent();
    });
});

describe('claiming and queueing', function () {
    it('lets exactly one caller claim an e-mail', function () {
        Queue::fake();
        Mail::fake();
        [$reference] = ($this->buy)();

        $this->tenant->run(function () use ($reference) {
            $order = Order::query()->where('reference', $reference)->first();

            expect(app(QueueOrderEmail::class)->execute($order, NotificationType::Confirmation))->toBeTrue()
                ->and(app(QueueOrderEmail::class)->execute($order, NotificationType::Confirmation))->toBeFalse()
                ->and(app(QueueOrderEmail::class)->execute($order, NotificationType::Reminder))->toBeTrue(); // another type is another e-mail
        });

        Queue::assertPushed(SendOrderEmailJob::class, 2);
        Queue::assertPushedOn('mail', SendOrderEmailJob::class);
    });

    it('queues nothing when the surrounding transaction rolls back', function () {
        Queue::fake();
        [$reference] = ($this->buy)();

        $this->tenant->run(function () use ($reference) {
            $order = Order::query()->where('reference', $reference)->first();

            try {
                DB::transaction(function () use ($order) {
                    app(QueueOrderEmail::class)->execute($order, NotificationType::Confirmation);
                    throw new RuntimeException('payment handling blew up');
                });
            } catch (RuntimeException) {
            }

            expect(DB::table('order_notifications')->count())->toBe(0); // the claim rolled back too
        });

    });

    it('survives a mail failure: retries, then records the failure, and never double-sends', function () {
        Queue::fake();
        [$reference] = ($this->buy)();
        $order = $this->tenant->run(function () use ($reference) {
            $order = Order::query()->where('reference', $reference)->first();
            app(QueueOrderEmail::class)->execute($order, NotificationType::Confirmation);

            return $order;
        });
        $job = new SendOrderEmailJob('acme-events', $order->id, NotificationType::Confirmation);
        $row = fn () => $this->tenant->run(fn () => DB::table('order_notifications')->sole());

        $manager = app(MailManager::class);

        // SMTP is down
        Mail::swap(new class
        {
            public function to(...$args): static
            {
                return $this;
            }

            public function send(...$args): never
            {
                throw new RuntimeException('smtp down');
            }
        });
        expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'smtp down');
        expect($row()->status)->toBe('pending')->and($row()->attempts)->toBe(1);

        $job->failed(new RuntimeException('smtp down')); // the queue gave up
        expect($row()->status)->toBe('failed')->and($row()->last_error)->toBe('smtp down');

        // an operator retries the job once SMTP is back
        Mail::swap(new MailFake($manager));
        $job->handle();
        $job->handle(); // a duplicate delivery of the same job
        Mail::assertSent(OrderConfirmationMail::class, 1);
        expect($row()->status)->toBe(NotificationStatus::Sent->value)->and($row()->attempts)->toBe(2)->and($row()->last_error)->toBeNull();
    });

    it('runs inside the tenant it was queued for, even from a central context', function () {
        Queue::fake();
        Mail::fake();
        [$reference, $total] = ($this->buy)();
        $order = $this->tenant->run(function () use ($reference) {
            $order = Order::query()->where('reference', $reference)->first();
            app(QueueOrderEmail::class)->execute($order, NotificationType::Confirmation);
            DB::table('order_notifications')->update(['status' => 'pending']);

            return $order;
        });
        $this->tenant->run(fn () => DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Paid->value])); // tickets need a paid order only for the page, not the mail

        tenancy()->end(); // a worker process has no tenant initialized
        expect(tenancy()->initialized)->toBeFalse();

        (new SendOrderEmailJob('acme-events', $order->id, NotificationType::Confirmation))->handle();

        Mail::assertSent(OrderConfirmationMail::class, fn ($mail) => $mail->hasTo('aisha@example.com'));
    });
});

describe('the e-mailed links', function () {
    beforeEach(function () {
        Mail::fake();
        [$this->reference, $total] = ($this->buy)();
        pay('acme-events', $this->reference, $total, 'evt_1');
        $this->link = fn (string $tenantId = 'acme-events', ?string $reference = null) => URL::temporarySignedRoute(
            'public.tickets.show', now()->addDays(30), ['tenant' => $tenantId, 'reference' => $reference ?? $this->reference],
        );
    });

    it('shows the tickets with signed QR links, without any login', function () {
        $response = $this->getJson(($this->link)())->assertOk()
            ->assertJsonPath('data.reference', $this->reference)
            ->assertJsonPath('data.event.title', 'Sunset Fest')
            ->assertJsonCount(3, 'data.tickets')
            ->assertJsonPath('data.tickets.0.ticket_type', 'VIP')
            ->assertJsonPath('data.tickets.0.status', 'valid');

        $qr = $response->json('data.tickets.0.qr_url');
        $image = $this->get($qr)->assertOk()->assertHeader('Content-Type', 'image/png');
        expect(substr($image->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
    });

    it('refuses an unsigned, tampered or expired link', function () {
        $this->getJson("/api/public/acme-events/orders/{$this->reference}/tickets")->assertForbidden();
        $this->getJson(str_replace($this->reference, 'ORD-SOMETHINGELSE', ($this->link)()))->assertForbidden();

        Carbon::setTestNow('2027-02-15 00:00:00'); // 45 days later, the link lived 30
        $this->getJson(($this->link)())->assertOk(); // freshly signed now: fine
        Carbon::setTestNow('2027-01-01 00:00:00');
        $stale = ($this->link)();
        Carbon::setTestNow('2027-02-15 00:00:00');
        $this->getJson($stale)->assertForbidden();
    });

    it('does not show the tickets of an order that is not paid', function () {
        $pending = $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'B', 'buyer_email' => 'b@example.com', 'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
        ], $this->pub)->json('data.reference');

        $this->getJson(($this->link)('acme-events', $pending))->assertNotFound();
    });

    it('cannot be replayed against another tenant', function () {
        provisionTenant('other-org', 'admin@other.test');

        // the signature covers the tenant in the path: swapping it breaks it
        $this->getJson(str_replace('acme-events', 'other-org', ($this->link)()))->assertForbidden();
        // a link properly signed for the other tenant finds nothing there
        $this->getJson(($this->link)('other-org'))->assertNotFound();

        $code = ($this->tickets)()->first()->code;
        $foreignQr = URL::temporarySignedRoute('public.tickets.qr', now()->addDay(), ['tenant' => 'other-org', 'code' => $code]);
        $this->get($foreignQr)->assertNotFound();
    });

    it('answers 404 for an unknown ticket code', function () {
        $this->get(URL::temporarySignedRoute('public.tickets.qr', now()->addDay(), ['tenant' => 'acme-events', 'code' => 'NOSUCHCODE']))->assertNotFound();
    });
});

describe('money formatting', function () {
    it('formats integer minor units for display without floats', function (int $minor, string $currency, string $expected) {
        expect(MoneyFormatter::format($minor, $currency))->toBe($expected);
    })->with([
        [23150, 'MYR', 'MYR 231.50'],
        [5, 'MYR', 'MYR 0.05'],
        [0, 'MYR', 'MYR 0.00'],
        [123456789, 'USD', 'USD 1,234,567.89'],
        [500, 'JPY', 'JPY 500'], // zero-decimal currency
        [-250, 'SGD', 'SGD -2.50'],
    ]);
});
