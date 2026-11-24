<?php

use App\Domain\Inventory\TicketStock;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->pub = ['X-Tenant-ID' => 'acme-events'];
    $this->admin = tenantHeaders($this->tenant);

    [$this->event, $this->early, $this->regular] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);
        $early = TicketType::factory()->for($event)->create(['name' => 'Early', 'price_minor' => 3000, 'quantity' => 5, 'sort_order' => 1]);
        $regular = TicketType::factory()->for($event)->create(['name' => 'Regular', 'price_minor' => 5000, 'quantity' => 10, 'sort_order' => 2, 'unlocks_after_ticket_type_id' => $early->id]);

        return [$event, $early, $regular];
    });

    $this->buy = fn (TicketType $tier, int $quantity = 1) => $this->postJson('/api/events/sunset/checkout', [
        'buyer_name' => 'Aisha', 'buyer_email' => 'a@example.com', 'items' => [['ticket_type_id' => $tier->id, 'quantity' => $quantity]],
    ], $this->pub);
    $this->status = fn () => collect($this->getJson('/api/events/sunset', $this->pub)->json('data.ticket_types'))->pluck('sales_status', 'name')->all();
});

describe('checkout', function () {
    it('refuses a locked tier with tier_locked and holds nothing', function () {
        ($this->buy)($this->regular)->assertStatus(409)->assertJsonPath('error', 'tier_locked');

        expect($this->tenant->run(fn () => TicketType::query()->find($this->regular->id)->only(['sold', 'held'])))->toBe(['sold' => 0, 'held' => 0]);
    });

    it('opens when the predecessor is sold out (held counts as gone)', function () {
        ($this->buy)($this->early, 4)->assertCreated();
        ($this->buy)($this->regular)->assertStatus(409)->assertJsonPath('error', 'tier_locked'); // 1 left in Early

        ($this->buy)($this->early, 1)->assertCreated();
        ($this->buy)($this->regular, 2)->assertCreated()->assertJsonPath('data.subtotal_minor', 10000);
    });

    it('opens when the predecessor sales window has ended', function () {
        $this->tenant->run(fn () => TicketType::query()->whereKey($this->early->id)->update(['sales_end_at' => '2027-01-05 00:00:00']));

        ($this->buy)($this->regular)->assertStatus(409)->assertJsonPath('error', 'tier_locked');

        Carbon::setTestNow('2027-01-05 00:00:00');
        ($this->buy)($this->regular)->assertCreated();
    });

    it('stays locked while the predecessor has no end of its own and still has stock', function () {
        // no sales_end_at on Early: it sells until the event ends, so Regular stays locked until then (and then nothing sells at all)
        Carbon::setTestNow('2027-02-01 00:00:00');
        ($this->buy)($this->regular)->assertStatus(409)->assertJsonPath('error', 'tier_locked');
    });

    it('decides inside the hold UPDATE: the primitive refuses a locked tier and accepts it once unlocked', function () {
        $this->tenant->run(function () {
            $locked = TicketStock::hold($this->regular->id, $this->event->id, 1, now());
            DB::table('ticket_types')->where('id', $this->early->id)->update(['sold' => 5]);
            $open = TicketStock::hold($this->regular->id, $this->event->id, 1, now());

            expect($locked)->toBeNull()->and($open['name'])->toBe('Regular');
        });
    });

    it('locks a tier again if the predecessor gets stock back, without touching what was bought', function () {
        ($this->buy)($this->early, 5)->assertCreated();
        ($this->buy)($this->regular)->assertCreated();
        $this->tenant->run(fn () => DB::table('ticket_types')->where('id', $this->early->id)->update(['held' => 0])); // the holds expired

        ($this->buy)($this->regular)->assertStatus(409)->assertJsonPath('error', 'tier_locked');
    });
});

describe('what is shown', function () {
    it('shows locked on the storefront with the predecessor, and on_sale once unlocked', function () {
        $detail = $this->getJson('/api/events/sunset', $this->pub)->assertOk();
        expect(($this->status)())->toBe(['Early' => 'on_sale', 'Regular' => 'locked']);
        $detail->assertJsonPath('data.ticket_types.0.unlocks_after', null)
            ->assertJsonPath('data.ticket_types.1.unlocks_after', ['id' => $this->early->id, 'name' => 'Early']);

        ($this->buy)($this->early, 5)->assertCreated();
        Carbon::setTestNow('2027-01-01 00:01:00'); // past the 15 s availability cache
        expect(($this->status)())->toBe(['Early' => 'sold_out', 'Regular' => 'on_sale']);
    });

    it('shows locked in the admin ticket type resources', function () {
        $this->getJson("/api/admin/events/{$this->event->id}/ticket-types", $this->admin)->assertOk()
            ->assertJsonPath('data.1.sales_status', 'locked')
            ->assertJsonPath('data.1.unlocks_after_ticket_type_id', $this->early->id);
        $this->getJson("/api/admin/ticket-types/{$this->regular->id}", $this->admin)->assertJsonPath('data.sales_status', 'locked');
        $this->getJson("/api/admin/events/{$this->event->id}", $this->admin)->assertJsonPath('data.ticket_types.1.sales_status', 'locked');
    });

    it('does not run a query per tier for the predecessor', function () {
        $this->tenant->run(function () {
            foreach (range(1, 8) as $i) {
                TicketType::factory()->for($this->event)->create(['name' => "Extra {$i}", 'sort_order' => 10 + $i, 'unlocks_after_ticket_type_id' => $this->early->id]);
            }
        });

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->getJson("/api/admin/events/{$this->event->id}/ticket-types", $this->admin)->assertOk()->assertJsonCount(10, 'data');

        expect(count($queries))->toBeLessThan(8);
    });
});

describe('validation', function () {
    it('creates a tier that unlocks after another, and changes or clears it', function () {
        $id = $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", [
            'name' => 'Late', 'price_minor' => 8000, 'quantity' => 5, 'unlocks_after_ticket_type_id' => $this->regular->id,
        ], $this->admin)->assertCreated()->assertJsonPath('data.unlocks_after_ticket_type_id', $this->regular->id)->assertJsonPath('data.sales_status', 'locked')->json('data.id');

        $this->patchJson("/api/admin/ticket-types/{$id}", ['unlocks_after_ticket_type_id' => null], $this->admin)->assertOk()->assertJsonPath('data.sales_status', 'on_sale');
    });

    it('refuses a tier of another event, itself and a cycle', function () {
        $foreign = $this->tenant->run(fn () => TicketType::factory()->for(Event::factory()->published()->create())->create());

        $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", ['name' => 'X', 'price_minor' => 1, 'quantity' => 1, 'unlocks_after_ticket_type_id' => $foreign->id], $this->admin)
            ->assertStatus(422)->assertJsonValidationErrors(['unlocks_after_ticket_type_id']);
        $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", ['name' => 'X', 'price_minor' => 1, 'quantity' => 1, 'unlocks_after_ticket_type_id' => 99999], $this->admin)
            ->assertStatus(422)->assertJsonValidationErrors(['unlocks_after_ticket_type_id']);
        $this->patchJson("/api/admin/ticket-types/{$this->early->id}", ['unlocks_after_ticket_type_id' => $this->early->id], $this->admin)
            ->assertStatus(422)->assertJsonValidationErrors(['unlocks_after_ticket_type_id']);
        // Regular already waits for Early: Early waiting for Regular would be a loop
        $this->patchJson("/api/admin/ticket-types/{$this->early->id}", ['unlocks_after_ticket_type_id' => $this->regular->id], $this->admin)
            ->assertStatus(422)->assertJsonValidationErrors(['unlocks_after_ticket_type_id']);
    });

    it('refuses a longer cycle too', function () {
        $third = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['name' => 'Third', 'unlocks_after_ticket_type_id' => $this->regular->id]));

        $this->patchJson("/api/admin/ticket-types/{$this->early->id}", ['unlocks_after_ticket_type_id' => $third->id], $this->admin)->assertStatus(422);
    });

    it('lets the successor open for good when the predecessor is deleted (FK sets null)', function () {
        $this->deleteJson("/api/admin/ticket-types/{$this->early->id}", [], $this->admin)->assertNoContent();

        $this->getJson("/api/admin/ticket-types/{$this->regular->id}", $this->admin)->assertJsonPath('data.unlocks_after_ticket_type_id', null)->assertJsonPath('data.sales_status', 'on_sale');
    });
});

describe('isolation', function () {
    it('cannot point at a tier of another tenant', function () {
        $other = provisionTenant('other-org', 'admin@other.test');
        $foreignId = $other->run(fn () => TicketType::factory()->count(5)->for(Event::factory()->published()->create())->create()->last()->id); // id 5: no such tier here

        $this->postJson("/api/admin/events/{$this->event->id}/ticket-types", ['name' => 'X', 'price_minor' => 1, 'quantity' => 1, 'unlocks_after_ticket_type_id' => $foreignId], $this->admin)
            ->assertStatus(422);
    });
});
