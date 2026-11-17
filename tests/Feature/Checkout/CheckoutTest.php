<?php

use App\Domain\Inventory\TicketStock;
use App\Domain\Orders\Exceptions\InvalidOrderTransition;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\TicketType;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @param array<int, array{int, int}> $items [[ticket_type_id, quantity], ...] */
function checkoutBody(array $items, array $overrides = []): array
{
    return array_merge([
        'buyer_name' => 'Aisha Rahman',
        'buyer_email' => 'Aisha@Example.com',
        'items' => array_map(fn ($i) => ['ticket_type_id' => $i[0], 'quantity' => $i[1]], $items),
    ], $overrides);
}

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events'); // fee 5 % + 50
    $this->pub = ['X-Tenant-ID' => 'acme-events'];

    [$this->event, $this->vip, $this->general] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);
        $vip = TicketType::factory()->for($event)->create(['name' => 'VIP', 'price_minor' => 12000, 'quantity' => 10, 'max_per_order' => 4, 'sort_order' => 1]);
        $general = TicketType::factory()->for($event)->create(['name' => 'General', 'price_minor' => 5000, 'quantity' => 100, 'max_per_order' => 10, 'sort_order' => 2]);

        return [$event, $vip, $general];
    });

    $this->stock = fn (TicketType $tier) => $this->tenant->run(fn () => TicketType::query()->find($tier->id)->only(['sold', 'held']));
});

describe('placing an order', function () {
    it('holds the tickets and snapshots prices, fee and total in integer minor units', function () {
        $response = $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 1], [$this->general->id, 2]]), $this->pub)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.buyer_email', 'aisha@example.com')
            ->assertJsonPath('data.currency', 'MYR')
            ->assertJsonPath('data.subtotal_minor', 22000)   // 12000 + 2 * 5000
            ->assertJsonPath('data.fee_minor', 1250)         // per ticket: VIP 600 + 50, General 250 + 50 (x2)
            ->assertJsonPath('data.total_minor', 23250)
            ->assertJsonPath('data.items.0.unit_fee_minor', 650)
            ->assertJsonPath('data.items.1.line_fee_minor', 600)
            ->assertJsonPath('data.items.0.name', 'VIP')
            ->assertJsonPath('data.items.1.line_total_minor', 10000)
            ->assertJsonPath('data.expires_at', '2027-01-01T08:10:00+08:00'); // now + 10 min, shown in KL time

        expect($response->json('data.reference'))->toMatch('/^ORD-[A-Z0-9]{12}$/')
            ->and(($this->stock)($this->vip))->toBe(['sold' => 0, 'held' => 1])
            ->and(($this->stock)($this->general))->toBe(['sold' => 0, 'held' => 2]);
    });

    it('prices the spec worked example exactly: fee per ticket, total 159.97', function () {
        $ids = $this->tenant->run(fn () => [
            TicketType::factory()->for($this->event)->create(['name' => 'GA', 'price_minor' => 4500, 'quantity' => 50])->id,
            TicketType::factory()->for($this->event)->create(['name' => 'Early Bird', 'price_minor' => 1999, 'quantity' => 50])->id,
        ]);

        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$ids[0], 2], [$ids[1], 3]]), $this->pub)
            ->assertCreated()
            ->assertJsonPath('data.subtotal_minor', 14997)
            ->assertJsonPath('data.fee_minor', 1000)
            ->assertJsonPath('data.total_minor', 15997)
            ->assertJsonPath('data.items.0.line_fee_minor', 550)   // 2 x (2.25 + 0.50)
            ->assertJsonPath('data.items.1.line_fee_minor', 450);  // 3 x (1.00 + 0.50), 0.9995 rounds half-up
    });

    it('keeps the price the buyer saw even if the tier is repriced afterwards', function () {
        $reference = $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->general->id, 1]]), $this->pub)->json('data.reference');

        $this->tenant->run(fn () => TicketType::query()->whereKey($this->general->id)->update(['price_minor' => 99999, 'name' => 'Renamed']));

        $this->getJson("/api/orders/{$reference}", $this->pub)
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price_minor', 5000)
            ->assertJsonPath('data.items.0.name', 'General')
            ->assertJsonPath('data.total_minor', 5300);
    });

    it('is all or nothing: one line that cannot be held releases the others and creates no order', function () {
        $this->tenant->run(fn () => TicketType::query()->whereKey($this->general->id)->update(['sold' => 100]));

        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 2], [$this->general->id, 1]]), $this->pub)
            ->assertStatus(409)->assertJsonPath('error', 'insufficient_stock');

        expect(($this->stock)($this->vip))->toBe(['sold' => 0, 'held' => 0])
            ->and($this->tenant->run(fn () => Order::query()->count()))->toBe(0);
    });

    it('sells exactly the capacity and then says sold out', function () {
        $this->tenant->run(fn () => TicketType::query()->whereKey($this->vip->id)->update(['quantity' => 3, 'max_per_order' => 3]));

        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 3]]), $this->pub)->assertCreated();
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 1]]), $this->pub)
            ->assertStatus(409)->assertJsonPath('error', 'insufficient_stock')->assertJsonPath('message', '"VIP" is sold out.');
        expect(($this->stock)($this->vip))->toBe(['sold' => 0, 'held' => 3]);
    });

    it('says how many are left when the request is too big', function () {
        $this->tenant->run(fn () => TicketType::query()->whereKey($this->vip->id)->update(['sold' => 8, 'held' => 1]));

        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 2]]), $this->pub)
            ->assertStatus(409)->assertJsonPath('message', 'Only 1 "VIP" tickets are left.');
    });

    it('enforces max per order and the global cap', function () {
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 5]]), $this->pub) // VIP max is 4
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->general->id, 101]]), $this->pub)
            ->assertStatus(422)->assertJsonValidationErrors('items.0.quantity', 'errors');
        expect(($this->stock)($this->vip)['held'])->toBe(0);
    });

    it('respects the sales window of a tier', function () {
        $tiers = $this->tenant->run(fn () => [
            'soon' => TicketType::factory()->for($this->event)->create(['sales_start_at' => '2027-01-02 00:00:00'])->id,
            'over' => TicketType::factory()->for($this->event)->create(['sales_end_at' => '2026-12-31 00:00:00'])->id,
        ]);

        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$tiers['soon'], 1]]), $this->pub)->assertStatus(409)->assertJsonPath('error', 'sales_not_started');
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$tiers['over'], 1]]), $this->pub)->assertStatus(409)->assertJsonPath('error', 'sales_ended');

        Carbon::setTestNow('2027-01-02 00:00:00'); // the window opens exactly now
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$tiers['soon'], 1]]), $this->pub)->assertCreated();
    });

    it('does not sell tickets of an event that is draft, cancelled, ended or unknown', function () {
        $ids = $this->tenant->run(function () {
            $draft = Event::factory()->create(['slug' => 'draft-one']);
            $cancelled = Event::factory()->cancelled()->create(['slug' => 'called-off']);
            $over = Event::factory()->published()->create(['slug' => 'over', 'starts_at' => '2026-11-01 10:00:00', 'ends_at' => '2026-11-01 12:00:00']);

            return [
                'draft-one' => TicketType::factory()->for($draft)->create()->id,
                'called-off' => TicketType::factory()->for($cancelled)->create()->id,
                'over' => TicketType::factory()->for($over)->create()->id,
            ];
        });

        foreach (['draft-one', 'called-off', 'nope'] as $slug) {
            $this->postJson("/api/events/{$slug}/checkout", checkoutBody([[$ids[$slug] ?? 1, 1]]), $this->pub)->assertNotFound();
        }
        $this->postJson('/api/events/over/checkout', checkoutBody([[$ids['over'], 1]]), $this->pub)
            ->assertStatus(409)->assertJsonPath('error', 'event_not_on_sale');
    });

    it('rejects a ticket type that belongs to another event', function () {
        $foreign = $this->tenant->run(fn () => TicketType::factory()->for(Event::factory()->published())->create()->id);

        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$foreign, 1]]), $this->pub)
            ->assertStatus(422)->assertJsonValidationErrors('items', 'errors');
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[999999, 1]]), $this->pub)->assertStatus(422);
    });

    it('validates the buyer and the items', function (array $override, string $field) {
        $this->postJson('/api/events/sunset/checkout', array_merge(checkoutBody([[$this->vip->id, 1]]), $override), $this->pub)
            ->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
    })->with([
        'no name' => [['buyer_name' => ''], 'buyer_name'],
        'bad email' => [['buyer_email' => 'not-an-email'], 'buyer_email'],
        'no items' => [['items' => []], 'items'],
        'zero quantity' => [['items' => [['ticket_type_id' => 1, 'quantity' => 0]]], 'items.0.quantity'],
        'fractional quantity' => [['items' => [['ticket_type_id' => 1, 'quantity' => 1.5]]], 'items.0.quantity'],
        'same tier twice' => [['items' => [['ticket_type_id' => 1, 'quantity' => 1], ['ticket_type_id' => 1, 'quantity' => 1]]], 'items.0.ticket_type_id'],
    ]);
});

describe('idempotency', function () {
    it('returns the same order for the same key without holding twice', function () {
        $headers = $this->pub + ['Idempotency-Key' => 'checkout-attempt-0001'];
        $body = checkoutBody([[$this->vip->id, 2]]);

        $first = $this->postJson('/api/events/sunset/checkout', $body, $headers)->assertCreated();
        $second = $this->postJson('/api/events/sunset/checkout', $body, $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        expect($second->json('data.reference'))->toBe($first->json('data.reference'))
            ->and(($this->stock)($this->vip)['held'])->toBe(2)
            ->and($this->tenant->run(fn () => Order::query()->count()))->toBe(1);
    });

    it('refuses a key reused for a different request', function () {
        $headers = $this->pub + ['Idempotency-Key' => 'checkout-attempt-0001'];

        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 1]]), $headers)->assertCreated();
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 2]]), $headers)
            ->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');

        expect(($this->stock)($this->vip)['held'])->toBe(1);
    });

    it('treats requests without a key as separate orders', function () {
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 1]]), $this->pub)->assertCreated();
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 1]]), $this->pub)->assertCreated();

        expect($this->tenant->run(fn () => Order::query()->count()))->toBe(2);
    });

    it('is enforced by the database: one key, one order', function () {
        $this->tenant->run(function () {
            Order::factory()->for($this->event)->create(['idempotency_key' => 'same-key-12345']);

            expect(fn () => Order::factory()->for($this->event)->create(['idempotency_key' => 'same-key-12345']))
                ->toThrow(UniqueConstraintViolationException::class);
        });
    });

    it('rejects a malformed key', function () {
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 1]]), $this->pub + ['Idempotency-Key' => 'bad key!'])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key', 'errors');
    });
});

describe('looking up an order', function () {
    it('shows an order to whoever holds its reference, only inside its own tenant', function () {
        $reference = $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->vip->id, 1]]), $this->pub)->json('data.reference');
        provisionTenant('other-org', 'admin@other.test');

        $this->getJson("/api/orders/{$reference}", $this->pub)->assertOk()->assertJsonPath('data.reference', $reference);
        $this->getJson('/api/orders/ORD-DOESNOTEXIST', $this->pub)->assertNotFound();
        $this->getJson("/api/orders/{$reference}", ['X-Tenant-ID' => 'other-org'])->assertNotFound();
    });
});

describe('abuse protection', function () {
    it('limits one buyer e-mail strictly but lets other buyers in', function () {
        config(['ticketing.rate_limits.checkout_per_minute_email' => 2]);

        foreach (range(1, 2) as $_) {
            $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->general->id, 1]]), $this->pub)->assertCreated();
        }
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->general->id, 1]]), $this->pub)
            ->assertStatus(429)->assertJsonPath('error', 'too_many_requests');
        $this->postJson('/api/events/sunset/checkout', checkoutBody([[$this->general->id, 1]], ['buyer_email' => 'someone.else@example.com']), $this->pub)->assertCreated();
    });

    it('keeps the per-IP limit high enough for the stress test', function () {
        expect(config('ticketing.rate_limits.checkout_per_minute_ip'))->toBeGreaterThanOrEqual(200);
    });
});

describe('inventory primitives', function () {
    it('never hands out more than the quantity, one conditional update at a time', function () {
        $this->tenant->run(function () {
            TicketType::query()->whereKey($this->general->id)->update(['quantity' => 5, 'max_per_order' => 5]);

            $granted = collect(range(1, 12))->filter(fn () => TicketStock::hold($this->general->id, $this->event->id, 1, now()) !== null)->count();

            expect($granted)->toBe(5)
                ->and(TicketType::query()->find($this->general->id)->only(['sold', 'held']))->toBe(['sold' => 0, 'held' => 5]);
        });
    });

    it('moves held to sold and back without breaking sold + held <= quantity', function () {
        $this->tenant->run(function () {
            TicketStock::hold($this->vip->id, $this->event->id, 3, now());
            TicketStock::commitHold($this->vip->id, 2);
            expect(TicketType::query()->find($this->vip->id)->only(['sold', 'held']))->toBe(['sold' => 2, 'held' => 1]);

            TicketStock::release($this->vip->id, 1);
            TicketStock::returnSold($this->vip->id, 2);
            expect(TicketType::query()->find($this->vip->id)->only(['sold', 'held']))->toBe(['sold' => 0, 'held' => 0]);

            expect(fn () => TicketStock::release($this->vip->id, 1))->toThrow(LogicException::class); // never goes negative
        });
    });

    it('lets a late sale through only while stock is left', function () {
        $this->tenant->run(function () {
            TicketType::query()->whereKey($this->vip->id)->update(['quantity' => 2, 'sold' => 1]);

            expect(TicketStock::sellWithoutHold($this->vip->id, 1))->toBeTrue()
                ->and(TicketStock::sellWithoutHold($this->vip->id, 1))->toBeFalse();
        });
    });
});

describe('order state machine', function () {
    beforeEach(function () {
        $this->order = fn (OrderStatus $status = OrderStatus::Pending) => $this->tenant->run(fn () => Order::factory()->for($this->event)->create(['status' => $status]));
    });

    it('allows only the documented moves', function (OrderStatus $from, OrderStatus $to, bool $allowed) {
        expect(OrderStateMachine::canTransition($from, $to))->toBe($allowed);
    })->with([
        [OrderStatus::Pending, OrderStatus::Paid, true],
        [OrderStatus::Pending, OrderStatus::Failed, true],
        [OrderStatus::Pending, OrderStatus::Expired, true],
        [OrderStatus::Pending, OrderStatus::Refunded, false],
        [OrderStatus::Expired, OrderStatus::Paid, true],
        [OrderStatus::Paid, OrderStatus::Refunded, true],
        [OrderStatus::Paid, OrderStatus::Pending, false],
        [OrderStatus::Paid, OrderStatus::Expired, false],
        [OrderStatus::Failed, OrderStatus::Paid, false],
        [OrderStatus::Refunded, OrderStatus::Paid, false],
    ]);

    it('stamps the matching timestamp and persists the new status', function () {
        $order = ($this->order)();

        $this->tenant->run(function () use ($order) {
            OrderStateMachine::transition($order, OrderStatus::Paid);

            $fresh = Order::query()->find($order->id);
            expect($fresh->status)->toBe(OrderStatus::Paid)->and($fresh->paid_at)->not->toBeNull()->and($fresh->failed_at)->toBeNull();
        });
    });

    it('lets only one of two racing transitions win', function () {
        $order = ($this->order)();

        $this->tenant->run(function () use ($order) {
            $copyA = Order::query()->find($order->id);
            $copyB = Order::query()->find($order->id); // both think the order is still pending

            OrderStateMachine::transition($copyA, OrderStatus::Paid);

            expect(fn () => OrderStateMachine::transition($copyB, OrderStatus::Expired))->toThrow(InvalidOrderTransition::class);
            expect(Order::query()->find($order->id)->status)->toBe(OrderStatus::Paid);
        });
    });

    it('refuses an illegal move and any direct write of the status', function () {
        $order = ($this->order)(OrderStatus::Failed);

        $this->tenant->run(function () use ($order) {
            expect(fn () => OrderStateMachine::transition($order, OrderStatus::Paid))->toThrow(InvalidOrderTransition::class);

            $fresh = Order::query()->find($order->id);
            $fresh->forceFill(['status' => OrderStatus::Paid]);
            expect(fn () => $fresh->save())->toThrow(LogicException::class);
        });
    });

    it('is backed by a database CHECK on the status column', function () {
        $order = ($this->order)();

        $this->tenant->run(function () use ($order) {
            expect(fn () => DB::statement("UPDATE orders SET status = 'teleported' WHERE id = ?", [$order->id]))->toThrow(QueryException::class);
            expect(fn () => DB::statement('UPDATE orders SET total_minor = total_minor + 1 WHERE id = ?', [$order->id]))->toThrow(QueryException::class); // total = subtotal + fee
        });
    });
});
