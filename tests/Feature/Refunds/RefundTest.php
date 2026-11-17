<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Mail\OrderRefundedMail;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\Refund;
use App\Models\Tenant\Ticket;
use App\Models\Tenant\TicketType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->admin = tenantHeaders($this->tenant);
    $this->door = tenantHeaders($this->tenant, UserRole::DoorStaff);
    $this->pub = ['X-Tenant-ID' => 'acme-events'];

    [$this->event, $this->type] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'title' => 'Sunset Fest', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);

        return [$event, TicketType::factory()->for($event)->create(['name' => 'General', 'price_minor' => 5000, 'quantity' => 20, 'max_per_order' => 10])];
    });

    $this->checkout = fn (int $quantity, string $email = 'aisha@example.com', ?int $typeId = null) => $this->postJson('/api/events/sunset/checkout', [
        'buyer_name' => 'Aisha Rahman', 'buyer_email' => $email,
        'items' => [['ticket_type_id' => $typeId ?? $this->type->id, 'quantity' => $quantity]],
    ], $this->pub)->assertCreated();

    // A paid order: returns its reference.
    $this->sell = function (int $quantity = 2, string $email = 'aisha@example.com') {
        $order = ($this->checkout)($quantity, $email);
        $body = json_encode(['id' => 'evt_'.uniqid(), 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($order->json('data.reference')), 'amount' => $order->json('data.total_minor'), 'currency' => 'MYR']);
        $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
        ], $body)->assertOk();

        return $order->json('data.reference');
    };
    $this->stock = fn () => $this->tenant->run(fn () => TicketType::query()->find($this->type->id)->only(['sold', 'held']));
    $this->refund = fn (string $reference, array $body = [], ?array $headers = null) => $this->postJson("/api/admin/orders/{$reference}/refund", $body, $headers ?? $this->admin);
});

describe('refunding an order', function () {
    it('refunds a paid order in full: status, refund row, tickets, stock', function () {
        $reference = ($this->sell)(2);
        expect(($this->stock)())->toBe(['sold' => 2, 'held' => 0]);

        ($this->refund)($reference, ['reason' => 'Buyer asked'])->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.tickets.0.status', 'void')
            ->assertJsonPath('data.tickets.1.status', 'void');

        $this->tenant->run(function () use ($reference) {
            $order = Order::query()->where('reference', $reference)->with('refund')->sole();

            expect($order->status)->toBe(OrderStatus::Refunded)
                ->and($order->refunded_at)->not->toBeNull()
                ->and($order->refund->amount_minor)->toBe($order->total_minor)
                ->and($order->refund->currency)->toBe('MYR')
                ->and($order->refund->reason)->toBe('Buyer asked')
                ->and($order->refund->refunded_by)->not->toBeNull()
                ->and(Ticket::query()->where('order_id', $order->id)->where('status', TicketStatus::Void)->count())->toBe(2);
        });
        expect(($this->stock)())->toBe(['sold' => 0, 'held' => 0]);
    });

    it('lets the freed tickets be bought again', function () {
        $reference = ($this->sell)(10);
        ($this->sell)(10, 'b@example.com'); // sold out
        $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'C', 'buyer_email' => 'c@example.com', 'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
        ], $this->pub)->assertStatus(409)->assertJsonPath('error', 'insufficient_stock');

        ($this->refund)($reference)->assertOk();

        ($this->checkout)(10, 'c@example.com');
    });

    it('e-mails the buyer once', function () {
        $reference = ($this->sell)(1);

        ($this->refund)($reference)->assertOk();

        Mail::assertSent(OrderRefundedMail::class, 1);
        Mail::assertSent(OrderRefundedMail::class, function (OrderRefundedMail $mail) use ($reference) {
            $this->tenant->run(function () use ($mail, $reference) {
                $mail->assertTo('aisha@example.com');
                $mail->assertHasSubject("Your order {$reference} was refunded");
                $mail->assertSeeInHtml('MYR 53.00'); // 5000 + fee 5% (250) + fixed 50
                $mail->assertSeeInHtml('Sunset Fest');
            });

            return true;
        });
    });

    it('cannot refund twice: no second refund, no second stock return, no second e-mail', function () {
        $reference = ($this->sell)(2);
        ($this->sell)(3, 'b@example.com');

        ($this->refund)($reference)->assertOk();
        ($this->refund)($reference)->assertStatus(409)->assertJsonPath('error', 'invalid_order_state');

        expect(($this->stock)())->toBe(['sold' => 3, 'held' => 0])
            ->and($this->tenant->run(fn () => Refund::query()->count()))->toBe(1);
        Mail::assertSent(OrderRefundedMail::class, 1);
    });

    it('is enforced by the database too: one refund row per order', function () {
        $reference = ($this->sell)(1);
        ($this->refund)($reference)->assertOk();

        $this->tenant->run(function () use ($reference) {
            $orderId = Order::query()->where('reference', $reference)->value('id');

            expect(fn () => DB::table('refunds')->insert(['order_id' => $orderId, 'amount_minor' => 1, 'currency' => 'MYR', 'created_at' => now(), 'updated_at' => now()]))
                ->toThrow(UniqueConstraintViolationException::class);
        });
    });

    it('refuses an order that is not paid', function () {
        $pending = ($this->checkout)(1)->json('data.reference');

        ($this->refund)($pending)->assertStatus(409)->assertJsonPath('error', 'invalid_order_state');
        expect(($this->stock)())->toBe(['sold' => 0, 'held' => 1]);
    });

    it('refunds a refund_pending order (late payment, no stock) without touching stock or tickets', function () {
        $reference = ($this->checkout)(2)->json('data.reference');
        // What a late payment with no stock left leaves behind: money taken, hold gone, no tickets.
        $this->tenant->run(function () use ($reference) {
            DB::table('orders')->where('reference', $reference)->update(['status' => OrderStatus::RefundPending->value]);
            DB::table('ticket_types')->where('id', $this->type->id)->update(['held' => 0, 'sold' => 18]);
        });

        ($this->refund)($reference, ['reason' => 'Sold out meanwhile'])->assertOk()->assertJsonPath('data.status', 'refunded');

        expect(($this->stock)())->toBe(['sold' => 18, 'held' => 0]) // nothing was returned: this order never held stock
            ->and($this->tenant->run(fn () => Refund::query()->count()))->toBe(1);
    });

    it('refuses, and changes nothing, when a ticket was already used at the door', function () {
        $reference = ($this->sell)(3);
        $code = $this->tenant->run(fn () => Ticket::query()->orderBy('id')->value('code'));
        $this->postJson("/api/admin/events/{$this->event->id}/check-ins", ['code' => $code], $this->door)->assertOk();

        ($this->refund)($reference)->assertStatus(409)->assertJsonPath('error', 'tickets_already_used');

        $this->tenant->run(function () use ($reference) {
            expect(Order::query()->where('reference', $reference)->value('status'))->toBe(OrderStatus::Paid)
                ->and(Refund::query()->count())->toBe(0)
                ->and(Ticket::query()->where('status', TicketStatus::Void)->count())->toBe(0) // the voiding rolled back
                ->and(Ticket::query()->where('status', TicketStatus::Valid)->count())->toBe(2);
        });
        expect(($this->stock)())->toBe(['sold' => 3, 'held' => 0]);
        Mail::assertNotSent(OrderRefundedMail::class);
    });

    it('stops the refunded tickets at the door', function () {
        $reference = ($this->sell)(1);
        $code = $this->tenant->run(fn () => Ticket::query()->value('code'));
        ($this->refund)($reference)->assertOk();

        $this->postJson("/api/admin/events/{$this->event->id}/check-ins", ['code' => $code], $this->door)->assertStatus(409)->assertJsonPath('error', 'ticket_void');
    });

    it('refunds a free order for zero', function () {
        $free = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['name' => 'Free', 'price_minor' => 0, 'quantity' => 5]));
        $reference = ($this->checkout)(2, 'aisha@example.com', $free->id)->assertJsonPath('data.status', 'paid')->json('data.reference');

        ($this->refund)($reference)->assertOk()->assertJsonPath('data.refund.amount_minor', 0);
    });
});

describe('access and isolation', function () {
    it('is for admins only', function () {
        $reference = ($this->sell)(1);

        ($this->refund)($reference, [], $this->door)->assertForbidden();
        $this->postJson("/api/admin/orders/{$reference}/refund", [], $this->pub)->assertUnauthorized();
        expect($this->tenant->run(fn () => Order::query()->value('status')))->toBe(OrderStatus::Paid);
    });

    it('answers 404 for an unknown reference', function () {
        ($this->refund)('ORD-DOESNOTEXIST')->assertNotFound();
    });

    it('cannot reach another tenant\'s order', function () {
        $reference = ($this->sell)(1);
        $other = provisionTenant('other-org', 'admin@other.test');
        $otherAdmin = tenantHeaders($other);

        ($this->refund)($reference, [], $otherAdmin)->assertNotFound();
        $this->getJson("/api/admin/orders/{$reference}", $otherAdmin)->assertNotFound();
        $this->getJson('/api/admin/orders', $otherAdmin)->assertOk()->assertJsonCount(0, 'data');
        expect($this->tenant->run(fn () => Order::query()->value('status')))->toBe(OrderStatus::Paid);
    });

    it('validates the reason', function () {
        $reference = ($this->sell)(1);

        ($this->refund)($reference, ['reason' => str_repeat('x', 501)])->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    });
});

describe('admin order list and detail', function () {
    it('lists orders newest first, paginated, with filters', function () {
        $first = ($this->sell)(1, 'one@example.com');
        Carbon::setTestNow(now()->addMinute());
        $second = ($this->sell)(1, 'two@example.com');
        Carbon::setTestNow(now()->addMinute());
        $pending = ($this->checkout)(1, 'three@example.com')->json('data.reference');
        $admin = tenantHeaders($this->tenant); // fresh token: the clock moved

        $all = $this->getJson('/api/admin/orders', $admin)->assertOk();
        expect(array_column($all->json('data'), 'reference'))->toBe([$pending, $second, $first]);
        $all->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total']]);

        $this->getJson('/api/admin/orders?per_page=2&page=2', $admin)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        expect(array_column($this->getJson('/api/admin/orders?status=paid', $admin)->json('data'), 'reference'))->toBe([$second, $first]);
        expect(array_column($this->getJson('/api/admin/orders?email=TWO@example.com', $admin)->json('data'), 'reference'))->toBe([$second]);
        expect(array_column($this->getJson('/api/admin/orders?reference='.strtolower($first), $admin)->json('data'), 'reference'))->toBe([$first]);
        $this->getJson("/api/admin/orders?event_id={$this->event->id}", $admin)->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/orders?event_id=999999', $admin)->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/orders?status=bogus', $admin)->assertStatus(422);
    });

    it('shows one order with items, tickets and refund', function () {
        $reference = ($this->sell)(2);
        ($this->refund)($reference, ['reason' => 'Test'])->assertOk();

        $this->getJson("/api/admin/orders/{$reference}", $this->admin)->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('data.items.0.name', 'General')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonCount(2, 'data.tickets')
            ->assertJsonPath('data.refund.reason', 'Test')
            ->assertJsonPath('data.refund.amount_minor', 10600) // 2 x (5000 + 5% + 50 fixed)
            ->assertJsonPath('data.refunded_at', fn ($at) => $at !== null);
    });

    it('is for admins only', function () {
        $this->getJson('/api/admin/orders', $this->door)->assertForbidden();
    });
});

describe('refunding every order of a cancelled event', function () {
    it('queues one refund per paid order and ignores everything else', function () {
        $a = ($this->sell)(2, 'a@example.com');
        $b = ($this->sell)(1, 'b@example.com');
        $pending = ($this->checkout)(1, 'c@example.com')->json('data.reference');
        $this->postJson("/api/admin/events/{$this->event->id}/cancel", [], $this->admin)->assertOk();

        $this->postJson("/api/admin/events/{$this->event->id}/refund-orders", [], $this->admin)->assertStatus(202)->assertJsonPath('data.queued', 2);

        $this->tenant->run(function () use ($a, $b, $pending) {
            expect(Order::query()->whereIn('reference', [$a, $b])->pluck('status')->unique()->all())->toBe([OrderStatus::Refunded])
                ->and(Order::query()->where('reference', $pending)->value('status'))->toBe(OrderStatus::Pending)
                ->and(Refund::query()->whereNull('refunded_by')->count())->toBe(0) // done by the admin who asked
                ->and(Refund::query()->where('reason', 'Event cancelled')->count())->toBe(2);
        });
        Mail::assertSent(OrderRefundedMail::class, 2);
        expect(($this->stock)())->toBe(['sold' => 0, 'held' => 1]);
    });

    it('is safe to run twice', function () {
        ($this->sell)(1);
        $this->postJson("/api/admin/events/{$this->event->id}/cancel", [], $this->admin)->assertOk();

        $this->postJson("/api/admin/events/{$this->event->id}/refund-orders", [], $this->admin)->assertStatus(202)->assertJsonPath('data.queued', 1);
        $this->postJson("/api/admin/events/{$this->event->id}/refund-orders", [], $this->admin)->assertStatus(202)->assertJsonPath('data.queued', 0);

        Mail::assertSent(OrderRefundedMail::class, 1);
    });

    it('only works for a cancelled event, and only for admins', function () {
        ($this->sell)(1);

        $this->postJson("/api/admin/events/{$this->event->id}/refund-orders", [], $this->admin)->assertStatus(409)->assertJsonPath('error', 'invalid_event_state');
        $this->tenant->run(fn () => DB::table('events')->update(['status' => EventStatus::Cancelled->value]));
        $this->postJson("/api/admin/events/{$this->event->id}/refund-orders", [], $this->door)->assertForbidden();
    });
});
