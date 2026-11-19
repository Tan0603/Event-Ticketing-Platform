<?php

use App\Enums\UserRole;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @param array<string, mixed> $overrides */
function tierPayload(array $overrides = []): array
{
    return array_merge(['name' => 'General', 'price_minor' => 5000, 'quantity' => 100], $overrides);
}

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->h = tenantHeaders($this->tenant);
    $this->event = $this->tenant->run(fn () => Event::factory()->create([
        'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00',
    ]));
});

describe('creating ticket types', function () {
    it('creates a tier with integer minor-unit price, defaults and a computed status', function () {
        $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", tierPayload(), $this->h)
            ->assertCreated()
            ->assertJsonPath('data.price_minor', 5000)
            ->assertJsonPath('data.currency', 'MYR')
            ->assertJsonPath('data.quantity', 100)
            ->assertJsonPath('data.sold', 0)
            ->assertJsonPath('data.held', 0)
            ->assertJsonPath('data.available', 100)
            ->assertJsonPath('data.max_per_order', config('ticketing.max_per_order_default'))
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonPath('data.sales_status', 'not_published'); // the event is still a draft
    });

    it('appends new tiers after existing ones', function () {
        $url = "/api/admin/events/{$this->event->id}/ticket-types";
        $this->postJson($url, tierPayload(['name' => 'A']), $this->h)->assertJsonPath('data.sort_order', 1);
        $this->postJson($url, tierPayload(['name' => 'B']), $this->h)->assertJsonPath('data.sort_order', 2);
        $this->postJson($url, tierPayload(['name' => 'C', 'sort_order' => 0]), $this->h)->assertJsonPath('data.sort_order', 0);

        $this->getJson($url, $this->h)->assertOk()->assertJsonPath('data.0.name', 'C');
    });

    it('allows a free tier but never a float or negative price', function () {
        $url = "/api/admin/events/{$this->event->id}/ticket-types";

        $this->postJson($url, tierPayload(['price_minor' => 0]), $this->h)->assertCreated();
        foreach ([50.5, '50.00', -1, 'abc'] as $bad) {
            $this->postJson($url, tierPayload(['price_minor' => $bad]), $this->h)
                ->assertStatus(422)->assertJsonValidationErrors('price_minor', 'errors');
        }
    });

    it('validates quantity, max per order and the sales window', function (array $override, string $field) {
        $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", tierPayload($override), $this->h)
            ->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
    })->with([
        'zero quantity' => [['quantity' => 0], 'quantity'],
        'negative quantity' => [['quantity' => -5], 'quantity'],
        'zero per order' => [['max_per_order' => 0], 'max_per_order'],
        'per order over cap' => [['max_per_order' => 101], 'max_per_order'],
        'end before start' => [['sales_start_at' => '2027-02-10T10:00:00Z', 'sales_end_at' => '2027-02-09T10:00:00Z'], 'sales_end_at'],
        'sales end after event end' => [['sales_end_at' => '2027-03-01T15:00:01Z'], 'sales_end_at'],
        'sales start after event end' => [['sales_start_at' => '2027-03-02T00:00:00Z'], 'sales_start_at'],
    ]);

    it('accepts a sales end exactly at the event end', function () {
        $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", tierPayload(['sales_end_at' => '2027-03-01T15:00:00Z']), $this->h)
            ->assertCreated();
    });

    it('cannot add tiers to a cancelled event', function () {
        $cancelled = $this->tenant->run(fn () => Event::factory()->cancelled()->create());

        $this->postJson("/api/admin/events/{$cancelled->id}/ticket-types", tierPayload(), $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'event_cancelled');
    });
});

describe('sales status', function () {
    it('follows the sales window, stock and event state', function () {
        $published = $this->tenant->run(fn () => Event::factory()->published()->create(['starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']));
        $ids = $this->tenant->run(fn () => [
            'scheduled' => TicketType::factory()->for($published)->create(['sales_start_at' => '2027-02-01 00:00:00'])->id,
            'ended' => TicketType::factory()->for($published)->create(['sales_end_at' => '2026-12-31 00:00:00'])->id,
            'sold_out' => TicketType::factory()->for($published)->create(['quantity' => 10, 'sold' => 7, 'held' => 3])->id,
            'on_sale' => TicketType::factory()->for($published)->create(['quantity' => 10, 'sold' => 7, 'held' => 2])->id,
        ]);

        foreach ($ids as $expected => $id) {
            $this->getJson("/api/admin/ticket-types/{$id}", $this->h)->assertOk()->assertJsonPath('data.sales_status', $expected);
        }

        $this->getJson("/api/admin/ticket-types/{$ids['on_sale']}", $this->h)->assertJsonPath('data.available', 1);

        // After the event itself ends, a tier with no end of its own stops selling.
        Carbon::setTestNow('2027-03-01 15:00:00');
        $this->h = tenantHeaders($this->tenant); // the first token is older than its lifetime now
        $this->getJson("/api/admin/ticket-types/{$ids['on_sale']}", $this->h)->assertJsonPath('data.sales_status', 'ended');
    });
});

describe('updating ticket types', function () {
    it('updates price and name', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create());

        $this->patchJson("/api/admin/ticket-types/{$tier->id}", ['name' => 'VIP', 'price_minor' => 12000], $this->h)
            ->assertOk()->assertJsonPath('data.name', 'VIP')->assertJsonPath('data.price_minor', 12000);
    });

    it('lets quantity grow, and shrink down to sold + held but not below', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['quantity' => 100, 'sold' => 30, 'held' => 10]));
        $url = "/api/admin/ticket-types/{$tier->id}";

        $this->patchJson($url, ['quantity' => 200], $this->h)->assertOk()->assertJsonPath('data.quantity', 200);
        $this->patchJson($url, ['quantity' => 40], $this->h)->assertOk()->assertJsonPath('data.available', 0);
        $this->patchJson($url, ['quantity' => 39], $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'quantity_below_sold');

        expect($this->tenant->run(fn () => TicketType::query()->find($tier->id)->quantity))->toBe(40);
    });

    it('does not apply other changes when the quantity change is refused', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['name' => 'Keep', 'quantity' => 10, 'sold' => 10]));

        $this->patchJson("/api/admin/ticket-types/{$tier->id}", ['name' => 'Changed', 'quantity' => 5], $this->h)->assertStatus(409);

        expect($this->tenant->run(fn () => TicketType::query()->find($tier->id)->name))->toBe('Keep'); // transaction rolled back
    });

    it('re-validates the sales window against the event', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create());

        $this->patchJson("/api/admin/ticket-types/{$tier->id}", ['sales_end_at' => '2027-04-01T00:00:00Z'], $this->h)
            ->assertStatus(422)->assertJsonValidationErrors('sales_end_at', 'errors');
    });

    it('cannot edit tiers of a cancelled event', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for(Event::factory()->cancelled())->create());

        $this->patchJson("/api/admin/ticket-types/{$tier->id}", ['name' => 'x'], $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'event_cancelled');
    });
});

describe('deleting ticket types', function () {
    it('deletes a tier with no sales', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create());

        $this->deleteJson("/api/admin/ticket-types/{$tier->id}", [], $this->h)->assertNoContent();
        expect($this->tenant->run(fn () => TicketType::query()->count()))->toBe(0);
    });

    it('refuses to delete a tier with sold or held tickets', function (array $counters) {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create($counters));

        $this->deleteJson("/api/admin/ticket-types/{$tier->id}", [], $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'ticket_type_has_sales');
    })->with([
        'sold' => [['sold' => 1]],
        'held' => [['held' => 1]],
    ]);

    it('keeps at least one tier on a published event', function () {
        $published = $this->tenant->run(fn () => Event::factory()->published()->create());
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($published)->create());

        $this->deleteJson("/api/admin/ticket-types/{$tier->id}", [], $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'last_ticket_type');
    });
});

describe('database guarantees', function () {
    it('refuses sold + held above quantity even for raw SQL', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['quantity' => 10]));

        $this->tenant->run(function () use ($tier) {
            expect(fn () => DB::statement('UPDATE ticket_types SET sold = 8, held = 3 WHERE id = ?', [$tier->id]))
                ->toThrow(QueryException::class, 'ticket_types_capacity_check');
            expect(fn () => DB::statement('UPDATE ticket_types SET held = -1 WHERE id = ?', [$tier->id]))
                ->toThrow(QueryException::class);
            expect(fn () => DB::statement('UPDATE ticket_types SET price_minor = -5 WHERE id = ?', [$tier->id]))
                ->toThrow(QueryException::class);
        });
    });

    it('refuses an event whose end is not after its start', function () {
        $this->tenant->run(function () {
            expect(fn () => Event::factory()->create(['starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 11:00:00']))
                ->toThrow(QueryException::class, 'events_dates_check');
        });
    });
});

describe('access control and isolation', function () {
    it('is admin only', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create());
        $door = tenantHeaders($this->tenant, UserRole::DoorStaff);

        $this->getJson("/api/admin/events/{$this->event->id}/ticket-types", $door)->assertForbidden();
        $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", tierPayload(), $door)->assertForbidden();
        $this->patchJson("/api/admin/ticket-types/{$tier->id}", ['name' => 'x'], $door)->assertForbidden();
        $this->deleteJson("/api/admin/ticket-types/{$tier->id}", [], $door)->assertForbidden();
    });

    it('does not let tenant B reach tenant A ticket types', function () {
        $tier = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['name' => 'Secret']));
        $b = tenantHeaders(provisionTenant('other-org', 'admin@other.test'));

        $this->getJson("/api/admin/events/{$this->event->id}/ticket-types", $b)->assertNotFound();
        $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", tierPayload(), $b)->assertNotFound();
        $this->getJson("/api/admin/ticket-types/{$tier->id}", $b)->assertNotFound();
        $this->patchJson("/api/admin/ticket-types/{$tier->id}", ['name' => 'Hacked'], $b)->assertNotFound();
        $this->deleteJson("/api/admin/ticket-types/{$tier->id}", [], $b)->assertNotFound();

        expect($this->tenant->run(fn () => TicketType::query()->find($tier->id)->name))->toBe('Secret');
    });
});
