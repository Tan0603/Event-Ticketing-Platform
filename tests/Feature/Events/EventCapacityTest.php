<?php

use App\Actions\Orders\ExpirePendingOrders;
use App\Domain\Inventory\TicketStock;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\TicketType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->pub = ['X-Tenant-ID' => 'acme-events'];
    $this->admin = tenantHeaders($this->tenant);

    // Two tiers of 50 under an event limit of 60.
    [$this->event, $this->a, $this->b] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'capacity' => 60, 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);
        $a = TicketType::factory()->for($event)->create(['name' => 'A', 'price_minor' => 5000, 'quantity' => 50, 'max_per_order' => 60, 'sort_order' => 1]);
        $b = TicketType::factory()->for($event)->create(['name' => 'B', 'price_minor' => 5000, 'quantity' => 50, 'max_per_order' => 60, 'sort_order' => 2]);

        return [$event, $a, $b];
    });

    $this->buy = fn (TicketType $tier, int $quantity = 1, string $email = 'a@example.com') => $this->postJson('/api/events/sunset/checkout', [
        'buyer_name' => 'Aisha', 'buyer_email' => $email, 'items' => [['ticket_type_id' => $tier->id, 'quantity' => $quantity]],
    ], $this->pub);
    $this->buyAll = fn (int $a, int $b, string $email = 'a@example.com') => $this->postJson('/api/events/sunset/checkout', [
        'buyer_name' => 'Aisha', 'buyer_email' => $email, 'items' => [['ticket_type_id' => $this->a->id, 'quantity' => $a], ['ticket_type_id' => $this->b->id, 'quantity' => $b]],
    ], $this->pub);
    $this->reserved = fn () => $this->tenant->run(fn () => Event::query()->find($this->event->id)->reserved);
    $this->pay = function (string $reference, int $total, string $id) {
        $body = json_encode(['id' => $id, 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($reference), 'amount' => $total, 'currency' => 'MYR']);

        return $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
        ], $body);
    };
});

describe('selling under an event limit', function () {
    it('sells 60 in total across both tiers, then answers event_sold_out', function () {
        ($this->buy)($this->a, 50)->assertCreated();
        expect(($this->reserved)())->toBe(50);

        ($this->buy)($this->b, 11)->assertStatus(409)->assertJsonPath('error', 'event_sold_out')->assertJsonPath('message', 'Only 10 tickets are left for this event.');
        ($this->buy)($this->b, 10)->assertCreated();
        ($this->buy)($this->b, 1)->assertStatus(409)->assertJsonPath('error', 'event_sold_out')->assertJsonPath('message', 'This event is sold out.');

        expect(($this->reserved)())->toBe(60)
            ->and($this->tenant->run(fn () => TicketType::query()->orderBy('id')->get(['sold', 'held'])->map->only(['sold', 'held'])->all()))->toBe([['sold' => 0, 'held' => 50], ['sold' => 0, 'held' => 10]]);
    });

    it('keeps the tier error when the tier itself is the limit', function () {
        $this->tenant->run(fn () => Event::query()->whereKey($this->event->id)->update(['capacity' => 500]));

        ($this->buy)($this->a, 50)->assertCreated();
        ($this->buy)($this->a, 1)->assertStatus(409)->assertJsonPath('error', 'insufficient_stock');
        expect(($this->reserved)())->toBe(50); // the refused hold left the event counter alone
    });

    it('all-or-nothing: a failing second line gives the event counter back', function () {
        ($this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'A', 'buyer_email' => 'a@example.com',
            'items' => [['ticket_type_id' => $this->a->id, 'quantity' => 40], ['ticket_type_id' => $this->b->id, 'quantity' => 40]],
        ], $this->pub))->assertStatus(409)->assertJsonPath('error', 'event_sold_out');

        expect(($this->reserved)())->toBe(0)->and($this->tenant->run(fn () => TicketType::query()->sum('held')))->toBe(0);
    });

    it('does not change anything for an event without a capacity', function () {
        $this->tenant->run(fn () => Event::query()->whereKey($this->event->id)->update(['capacity' => null]));

        ($this->buy)($this->a, 50)->assertCreated();
        ($this->buy)($this->b, 50)->assertCreated();

        expect(($this->reserved)())->toBe(0);
    });

    it('decides with conditional UPDATEs: exactly the capacity is handed out, one ticket at a time', function () {
        $granted = $this->tenant->run(function () {
            $granted = 0;
            foreach (range(1, 80) as $i) {
                $tier = $i % 2 === 0 ? $this->a : $this->b;
                $granted += TicketStock::hold($tier->id, $this->event->id, 1, now()) === null ? 0 : 1;
            }

            return $granted;
        });

        expect($granted)->toBe(60)->and(($this->reserved)())->toBe(60);
    });

    it('is backed by CHECK constraints', function () {
        $this->tenant->run(function () {
            expect(fn () => DB::statement('UPDATE events SET reserved = 61 WHERE id = ?', [$this->event->id]))->toThrow(QueryException::class);
        });
    });
});

describe('giving capacity back', function () {
    it('returns it when the hold expires, so the room can be sold again', function () {
        ($this->buyAll)(50, 10)->assertCreated();
        ($this->buy)($this->b, 1)->assertStatus(409);

        Carbon::setTestNow('2027-01-01 00:11:00');
        $this->tenant->run(fn () => app(ExpirePendingOrders::class)->execute());

        expect(($this->reserved)())->toBe(0);
        ($this->buy)($this->b, 1)->assertCreated();
    });

    it('returns it on a failed payment and on a refund', function () {
        $failed = ($this->buy)($this->a, 5)->json('data.reference');
        $paid = ($this->buy)($this->a, 7)->assertCreated();
        expect(($this->reserved)())->toBe(12);

        $body = json_encode(['id' => 'whk_f', 'type' => 'payment.failed', 'payment_reference' => paymentRef($failed), 'amount' => 0, 'currency' => 'MYR']);
        $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret)], $body)->assertOk();
        expect(($this->reserved)())->toBe(7);

        ($this->pay)($paid->json('data.reference'), $paid->json('data.total_minor'), 'whk_p')->assertOk(); // hold -> sale: unchanged
        expect(($this->reserved)())->toBe(7);

        $this->postJson('/api/admin/orders/'.$paid->json('data.reference').'/refund', [], $this->admin)->assertOk();
        expect(($this->reserved)())->toBe(0);
    });

    it('answers a late payment with refund_pending when the event filled up meanwhile', function () {
        $late = ($this->buy)($this->a, 10)->assertCreated();
        Carbon::setTestNow('2027-01-01 00:11:00');
        $this->tenant->run(fn () => app(ExpirePendingOrders::class)->execute());
        ($this->buyAll)(10, 50, 'other@example.com')->assertCreated(); // takes the whole event

        ($this->pay)($late->json('data.reference'), $late->json('data.total_minor'), 'whk_late')->assertOk()->assertJsonPath('status', 'late_payment_no_stock');

        expect(($this->reserved)())->toBe(60)
            ->and($this->tenant->run(fn () => Order::query()->where('reference', $late->json('data.reference'))->value('status')->value))->toBe('refund_pending')
            ->and($this->tenant->run(fn () => TicketType::query()->whereKey($this->a->id)->value('sold')))->toBe(0);
    });

    it('honours a late payment while there is room', function () {
        $late = ($this->buy)($this->a, 10)->assertCreated();
        Carbon::setTestNow('2027-01-01 00:11:00');
        $this->tenant->run(fn () => app(ExpirePendingOrders::class)->execute());

        ($this->pay)($late->json('data.reference'), $late->json('data.total_minor'), 'whk_late')->assertOk()->assertJsonPath('status', 'paid_late');
        expect(($this->reserved)())->toBe(10);
    });
});

describe('setting the capacity', function () {
    it('creates an event with a capacity and shows it with what is left', function () {
        $id = $this->postJson('/api/admin/events', [
            'title' => 'Limited', 'venue_name' => 'Hall', 'starts_at' => '2027-05-01 10:00:00', 'ends_at' => '2027-05-01 14:00:00', 'capacity' => 100,
        ], $this->admin)->assertCreated()->assertJsonPath('data.capacity', 100)->assertJsonPath('data.capacity_remaining', 100)->json('data.id');

        $this->getJson("/api/admin/events/{$id}", $this->admin)->assertJsonPath('data.capacity', 100);
        $this->postJson('/api/admin/events', ['title' => 'Bad', 'venue_name' => 'Hall', 'starts_at' => '2027-05-01 10:00:00', 'ends_at' => '2027-05-01 14:00:00', 'capacity' => 0], $this->admin)
            ->assertStatus(422)->assertJsonValidationErrors(['capacity']);
    });

    it('refuses a capacity below what is sold or held, accepts it at that level, and recomputes the counter', function () {
        ($this->buy)($this->a, 30)->assertCreated();
        $this->tenant->run(fn () => DB::table('events')->where('id', $this->event->id)->update(['reserved' => 30]));

        $this->patchJson("/api/admin/events/{$this->event->id}", ['capacity' => 29], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['capacity']);
        $this->patchJson("/api/admin/events/{$this->event->id}", ['capacity' => 30], $this->admin)->assertOk()->assertJsonPath('data.capacity_remaining', 0);
        ($this->buy)($this->b, 1)->assertStatus(409)->assertJsonPath('error', 'event_sold_out');

        $this->patchJson("/api/admin/events/{$this->event->id}", ['capacity' => 100], $this->admin)->assertOk()->assertJsonPath('data.capacity_remaining', 70);
        ($this->buy)($this->b, 1)->assertCreated();
    });

    it('starts counting correctly when a capacity is added to an event that already sold tickets', function () {
        $this->tenant->run(fn () => Event::query()->whereKey($this->event->id)->update(['capacity' => null]));
        ($this->buy)($this->a, 20)->assertCreated();
        ($this->buy)($this->b, 5)->assertCreated();
        expect(($this->reserved)())->toBe(0);

        $this->patchJson("/api/admin/events/{$this->event->id}", ['capacity' => 40], $this->admin)->assertOk()->assertJsonPath('data.capacity_remaining', 15);
        expect(($this->reserved)())->toBe(25);
    });

    it('clears the capacity with null', function () {
        ($this->buy)($this->a, 20)->assertCreated();

        $this->patchJson("/api/admin/events/{$this->event->id}", ['capacity' => null], $this->admin)->assertOk()->assertJsonPath('data.capacity', null)->assertJsonPath('data.capacity_remaining', null);
        ($this->buy)($this->a, 30)->assertCreated();
        ($this->buy)($this->b, 50)->assertCreated();
    });
});

describe('what is shown', function () {
    it('shows capacity, what is left and sold_out on every tier once the event is full', function () {
        $detail = $this->getJson('/api/events/sunset', $this->pub)->assertOk()->assertJsonPath('data.capacity', 60)->assertJsonPath('data.capacity_remaining', 60);
        expect(collect($detail->json('data.ticket_types'))->pluck('available')->all())->toBe([50, 50]);

        ($this->buyAll)(50, 10)->assertCreated();
        Carbon::setTestNow('2027-01-01 00:01:00'); // past the 15 s availability cache

        $detail = $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.capacity_remaining', 0);
        expect(collect($detail->json('data.ticket_types'))->pluck('sales_status')->all())->toBe(['sold_out', 'sold_out'])
            ->and(collect($detail->json('data.ticket_types'))->pluck('available')->all())->toBe([0, 0]);

        $this->getJson('/api/events', $this->pub)->assertJsonPath('data.0.sold_out', true);
        $this->getJson("/api/admin/events/{$this->event->id}/ticket-types", $this->admin)->assertJsonPath('data.1.sales_status', 'sold_out')->assertJsonPath('data.1.available', 0);
    });

    it('shows no capacity for an event without a limit', function () {
        $this->tenant->run(fn () => Event::query()->whereKey($this->event->id)->update(['capacity' => null]));

        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.capacity', null)->assertJsonPath('data.capacity_remaining', null);
        $this->getJson('/api/events', $this->pub)->assertJsonPath('data.0.sold_out', false);
    });
});

describe('tenant isolation', function () {
    it('keeps the counters of two tenants apart', function () {
        $other = provisionTenant('other-org', 'admin@other.test');
        $otherEvent = $other->run(function () {
            $event = Event::factory()->published()->create(['slug' => 'sunset', 'capacity' => 5, 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);
            TicketType::factory()->for($event)->create(['quantity' => 50, 'max_per_order' => 50]);

            return $event;
        });

        ($this->buyAll)(50, 10)->assertCreated();

        $tierId = $other->run(fn () => TicketType::query()->sole()->id);
        $this->postJson('/api/events/sunset/checkout', ['buyer_name' => 'B', 'buyer_email' => 'b@example.com', 'items' => [['ticket_type_id' => $tierId, 'quantity' => 5]]], ['X-Tenant-ID' => 'other-org'])->assertCreated();
        expect($other->run(fn () => Event::query()->find($otherEvent->id)->reserved))->toBe(5)->and(($this->reserved)())->toBe(60);
        expect($this->tenant->run(fn () => Event::query()->find($this->event->id)->capacity))->toBe(60); // tenant B's own event was the only one touched
    });
});
