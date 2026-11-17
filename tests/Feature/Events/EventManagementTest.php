<?php

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Carbon;

/** @param array<string, mixed> $overrides */
function eventPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Sunset Festival',
        'venue' => 'Main Hall',
        'description' => 'Live music.',
        'starts_at' => '2027-03-01T19:00:00+08:00',
        'ends_at' => '2027-03-01T23:00:00+08:00',
    ], $overrides);
}

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->h = tenantHeaders($this->tenant);
});

describe('creating events', function () {
    it('creates a draft event with a generated slug and shows times in the tenant timezone', function () {
        $this->postJson('/api/admin/events', eventPayload(), $this->h)
            ->assertCreated()
            ->assertJsonPath('data.title', 'Sunset Festival')
            ->assertJsonPath('data.slug', 'sunset-festival')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.timing', 'upcoming')
            ->assertJsonPath('data.starts_at', '2027-03-01T19:00:00+08:00');

        $stored = $this->tenant->run(fn () => Event::query()->sole());
        expect($stored->starts_at->utc()->toDateTimeString())->toBe('2027-03-01 11:00:00'); // stored as UTC
    });

    it('reads a time without an offset in the tenant timezone', function () {
        $this->postJson('/api/admin/events', eventPayload(['starts_at' => '2027-03-01 19:00:00', 'ends_at' => '2027-03-01 23:00:00']), $this->h)
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2027-03-01T19:00:00+08:00');
    });

    it('makes generated slugs unique', function () {
        $this->postJson('/api/admin/events', eventPayload(), $this->h)->assertCreated()->assertJsonPath('data.slug', 'sunset-festival');
        $this->postJson('/api/admin/events', eventPayload(), $this->h)->assertCreated()->assertJsonPath('data.slug', 'sunset-festival-2');
    });

    it('rejects a duplicate explicit slug with a field error', function () {
        $this->postJson('/api/admin/events', eventPayload(['slug' => 'my-event']), $this->h)->assertCreated();

        $this->postJson('/api/admin/events', eventPayload(['slug' => 'my-event']), $this->h)
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonValidationErrors('slug', 'errors');
    });

    it('validates the input', function (array $override, string $field) {
        $this->postJson('/api/admin/events', eventPayload($override), $this->h)
            ->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
    })->with([
        'end before start' => [['ends_at' => '2027-03-01T18:00:00+08:00'], 'ends_at'],
        'end equals start' => [['ends_at' => '2027-03-01T19:00:00+08:00'], 'ends_at'],
        'missing title' => [['title' => ''], 'title'],
        'missing venue' => [['venue' => ''], 'venue'],
        'bad slug' => [['slug' => 'Not A Slug!'], 'slug'],
        'not a date' => [['starts_at' => 'tomorrow-ish'], 'starts_at'],
    ]);
});

describe('updating events', function () {
    it('updates only the fields sent', function () {
        $id = $this->postJson('/api/admin/events', eventPayload(), $this->h)->json('data.id');

        $this->patchJson("/api/admin/events/{$id}", ['venue' => 'Arena'], $this->h)
            ->assertOk()
            ->assertJsonPath('data.venue', 'Arena')
            ->assertJsonPath('data.title', 'Sunset Festival');
    });

    it('rejects moving only the end before the stored start', function () {
        $id = $this->postJson('/api/admin/events', eventPayload(), $this->h)->json('data.id');

        $this->patchJson("/api/admin/events/{$id}", ['ends_at' => '2027-03-01T10:00:00+08:00'], $this->h)
            ->assertStatus(422)->assertJsonValidationErrors('ends_at', 'errors');
    });

    it('keeps the slug of a published event', function () {
        $event = $this->tenant->run(fn () => Event::factory()->published()->create(['slug' => 'live']));

        $this->patchJson("/api/admin/events/{$event->id}", ['slug' => 'other'], $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'slug_locked');
        // the same slug sent again is not a change
        $this->patchJson("/api/admin/events/{$event->id}", ['slug' => 'live'], $this->h)->assertOk();
    });

    it('cannot edit a cancelled event', function () {
        $event = $this->tenant->run(fn () => Event::factory()->cancelled()->create());

        $this->patchJson("/api/admin/events/{$event->id}", ['venue' => 'x'], $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'event_cancelled');
    });

    it('cannot end the event before a ticket type stops selling', function () {
        $event = $this->tenant->run(function () {
            $event = Event::factory()->create(['starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);
            TicketType::factory()->for($event)->create(['sales_end_at' => '2027-03-01 14:00:00']);

            return $event;
        });

        $this->patchJson("/api/admin/events/{$event->id}", ['ends_at' => '2027-03-01T20:00:00+08:00'], $this->h) // 12:00 UTC < 14:00 UTC
            ->assertStatus(422)->assertJsonValidationErrors('ends_at', 'errors');
    });
});

describe('publishing and cancelling', function () {
    it('publishes a draft that has a ticket type', function () {
        $event = $this->tenant->run(function () {
            $event = Event::factory()->create();
            TicketType::factory()->for($event)->create();

            return $event;
        });

        $this->postJson("/api/admin/events/{$event->id}/publish", [], $this->h)
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.ticket_types.0.sales_status', 'on_sale');
    });

    it('will not publish without ticket types', function () {
        $event = $this->tenant->run(fn () => Event::factory()->create());

        $this->postJson("/api/admin/events/{$event->id}/publish", [], $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'no_ticket_types');
    });

    it('will not publish twice or after the event ended', function () {
        $published = $this->tenant->run(fn () => Event::factory()->published()->create());
        $past = $this->tenant->run(function () {
            $event = Event::factory()->create(['starts_at' => '2026-01-01 10:00:00', 'ends_at' => '2026-01-01 12:00:00']);
            TicketType::factory()->for($event)->create();

            return $event;
        });

        $this->postJson("/api/admin/events/{$published->id}/publish", [], $this->h)->assertStatus(409)->assertJsonPath('error', 'invalid_event_state');
        $this->postJson("/api/admin/events/{$past->id}/publish", [], $this->h)->assertStatus(409)->assertJsonPath('error', 'event_already_ended');
    });

    it('cancels a published event and marks its tickets as cancelled', function () {
        $event = $this->tenant->run(function () {
            $event = Event::factory()->published()->create();
            TicketType::factory()->for($event)->create();

            return $event;
        });

        $this->postJson("/api/admin/events/{$event->id}/cancel", [], $this->h)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.ticket_types.0.sales_status', 'cancelled');

        $this->postJson("/api/admin/events/{$event->id}/cancel", [], $this->h)->assertStatus(409);
        expect($this->tenant->run(fn () => Event::query()->find($event->id)->cancelled_at))->not->toBeNull();
    });
});

describe('deleting events', function () {
    it('deletes an event without sales together with its ticket types', function () {
        $event = $this->tenant->run(function () {
            $event = Event::factory()->create();
            TicketType::factory()->for($event)->create();

            return $event;
        });

        $this->deleteJson("/api/admin/events/{$event->id}", [], $this->h)->assertNoContent();

        expect($this->tenant->run(fn () => [Event::query()->count(), TicketType::query()->count()]))->toBe([0, 0]);
    });

    it('refuses to delete an event with sold or held tickets', function () {
        $event = $this->tenant->run(function () {
            $event = Event::factory()->published()->create();
            TicketType::factory()->for($event)->create(['sold' => 3]);

            return $event;
        });

        $this->deleteJson("/api/admin/events/{$event->id}", [], $this->h)
            ->assertStatus(409)->assertJsonPath('error', 'event_has_orders');
        expect($this->tenant->run(fn () => Event::query()->count()))->toBe(1);
    });
});

describe('listing and showing', function () {
    it('paginates, filters and aggregates in SQL', function () {
        $this->tenant->run(function () {
            Event::factory()->count(3)->published()->create();
            $draft = Event::factory()->create(['title' => 'Jazz Night']);
            TicketType::factory()->for($draft)->create(['quantity' => 100, 'sold' => 40]);
            TicketType::factory()->for($draft)->create(['quantity' => 50, 'sold' => 10]);
        });

        $this->getJson('/api/admin/events?per_page=2', $this->h)
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4)->assertJsonPath('meta.per_page', 2);

        $this->getJson('/api/admin/events?status=published', $this->h)->assertOk()->assertJsonPath('meta.total', 3);

        $this->getJson('/api/admin/events?q=jazz', $this->h)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ticket_types_count', 2)
            ->assertJsonPath('data.0.capacity', 150)
            ->assertJsonPath('data.0.tickets_sold', 50);

        $this->getJson('/api/admin/events?per_page=5000', $this->h)->assertStatus(422);
    });

    it('treats a search for % literally', function () {
        $this->tenant->run(fn () => Event::factory()->create(['title' => 'Plain']));

        $this->getJson('/api/admin/events?q=%25', $this->h)->assertOk()->assertJsonPath('meta.total', 0);
    });

    it('lists events with a constant number of queries', function () {
        $this->tenant->run(fn () => Event::factory()->count(15)->create());

        $count = 0;
        $this->tenant->run(function () use (&$count) {
            DB::listen(function () use (&$count) {
                $count++;
            });
        });

        $this->getJson('/api/admin/events?per_page=15', $this->h)->assertOk();

        // token lookup + user + count + page (+ a couple of framework queries): never grows with the rows
        expect($count)->toBeLessThan(10);
    });

    it('shows an event with its ticket types in order and computes the timing phase', function () {
        $event = $this->tenant->run(function () {
            $event = Event::factory()->published()->create(['starts_at' => '2026-12-31 20:00:00', 'ends_at' => '2027-01-01 02:00:00']);
            TicketType::factory()->for($event)->create(['name' => 'B', 'sort_order' => 2]);
            TicketType::factory()->for($event)->create(['name' => 'A', 'sort_order' => 1]);

            return $event;
        });

        $this->getJson("/api/admin/events/{$event->id}", $this->h)
            ->assertOk()
            ->assertJsonPath('data.timing', 'ongoing')
            ->assertJsonPath('data.ticket_types.0.name', 'A')
            ->assertJsonPath('data.ticket_types.1.name', 'B');

        Carbon::setTestNow('2027-01-02 00:00:00');
        $this->h = tenantHeaders($this->tenant); // the first token is older than its 12 h lifetime now
        $this->getJson("/api/admin/events/{$event->id}", $this->h)->assertJsonPath('data.timing', 'ended');
    });

    it('returns 404 for an unknown event', function () {
        $this->getJson('/api/admin/events/999', $this->h)->assertNotFound()->assertJsonPath('error', 'not_found');
    });
});

describe('access control and isolation', function () {
    it('requires authentication and the admin role', function () {
        $this->getJson('/api/admin/events', ['X-Tenant-ID' => 'acme-events'])->assertUnauthorized();

        $door = tenantHeaders($this->tenant, UserRole::DoorStaff);
        $this->getJson('/api/admin/events', $door)->assertForbidden()->assertJsonPath('error', 'forbidden');
        $this->postJson('/api/admin/events', eventPayload(), $door)->assertForbidden();
    });

    it('keeps tenants apart: tenant B cannot see, change or delete tenant A events', function () {
        $eventA = $this->tenant->run(function () {
            $event = Event::factory()->published()->create(['title' => 'Only A']);
            TicketType::factory()->for($event)->create();

            return $event;
        });
        $tenantB = provisionTenant('other-org', 'admin@other.test');
        $b = tenantHeaders($tenantB);

        $this->getJson('/api/admin/events', $b)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/admin/events/{$eventA->id}", $b)->assertNotFound();
        $this->patchJson("/api/admin/events/{$eventA->id}", ['title' => 'Hacked'], $b)->assertNotFound();
        $this->deleteJson("/api/admin/events/{$eventA->id}", [], $b)->assertNotFound();
        $this->postJson("/api/admin/events/{$eventA->id}/cancel", [], $b)->assertNotFound();

        // A's token is useless on B (and vice versa)
        $this->getJson('/api/admin/events', ['X-Tenant-ID' => 'other-org', 'Authorization' => $this->h['Authorization']])->assertUnauthorized();

        expect($this->tenant->run(fn () => Event::query()->find($eventA->id)->title))->toBe('Only A')
            ->and($this->tenant->run(fn () => Event::query()->find($eventA->id)->status))->toBe(EventStatus::Published);
    });
});
