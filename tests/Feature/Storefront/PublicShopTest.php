<?php

use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @return array<int, string> SQL statements that ran while $callback executed */
function sqlDuring(Closure $callback): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $callback();

    return $queries;
}

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->pub = ['X-Tenant-ID' => 'acme-events']; // visitors send no token
    $this->admin = tenantHeaders($this->tenant);
});

/** A published event with the given tiers: [name, price, quantity, sold, held]. */
function publishedEvent(array $attributes = [], array $tiers = [['General', 5000, 100, 0, 0]]): Event
{
    $event = Event::factory()->published()->create($attributes);
    foreach ($tiers as $i => [$name, $price, $quantity, $sold, $held]) {
        TicketType::factory()->for($event)->create([
            'name' => $name, 'price_minor' => $price, 'quantity' => $quantity, 'sold' => $sold, 'held' => $held, 'sort_order' => $i,
        ]);
    }

    return $event;
}

describe('listing', function () {
    it('shows only published events that have not ended, soonest first, without any login', function () {
        $this->tenant->run(function () {
            publishedEvent(['title' => 'Later', 'starts_at' => '2027-04-01 10:00:00', 'ends_at' => '2027-04-01 14:00:00']);
            publishedEvent(['title' => 'Sooner', 'starts_at' => '2027-02-01 10:00:00', 'ends_at' => '2027-02-01 14:00:00']);
            publishedEvent(['title' => 'Already over', 'starts_at' => '2026-11-01 10:00:00', 'ends_at' => '2026-11-01 14:00:00']);
            Event::factory()->create(['title' => 'A draft']);
            Event::factory()->cancelled()->create(['title' => 'Called off']);
        });

        $response = $this->getJson('/api/events', $this->pub)->assertOk();

        expect(collect($response->json('data'))->pluck('title')->all())->toBe(['Sooner', 'Later']);
        $response->assertJsonPath('meta.total', 2);
    });

    it('shows the cheapest price and whether everything is sold out, but no stock numbers', function () {
        $this->tenant->run(function () {
            publishedEvent(['title' => 'Open'], [['VIP', 12000, 10, 0, 0], ['General', 5000, 100, 40, 10]]);
            publishedEvent(['title' => 'Full', 'starts_at' => '2027-05-01 10:00:00', 'ends_at' => '2027-05-01 14:00:00'], [['General', 3000, 10, 6, 4]]);
        });

        $rows = collect($this->getJson('/api/events', $this->pub)->assertOk()->json('data'))->keyBy('title');

        expect($rows['Open']['from_price_minor'])->toBe(5000)
            ->and($rows['Open']['sold_out'])->toBeFalse()
            ->and($rows['Open']['currency'])->toBe('MYR')
            ->and($rows['Full']['sold_out'])->toBeTrue()
            ->and($rows['Open'])->not->toHaveKeys(['sold', 'held', 'quantity', 'tickets_available']);
    });

    it('paginates, searches and rejects absurd page sizes', function () {
        $this->tenant->run(function () {
            foreach (range(1, 5) as $i) {
                publishedEvent(['title' => "Event {$i}", 'starts_at' => "2027-02-0{$i} 10:00:00", 'ends_at' => "2027-02-0{$i} 14:00:00"]);
            }
            publishedEvent(['title' => 'Jazz Night', 'venue_name' => 'Blue Room', 'starts_at' => '2027-03-01 10:00:00', 'ends_at' => '2027-03-01 14:00:00']);
        });

        $this->getJson('/api/events?per_page=4', $this->pub)->assertJsonCount(4, 'data')->assertJsonPath('meta.total', 6);
        $this->getJson('/api/events?per_page=4&page=2', $this->pub)->assertJsonCount(2, 'data');
        $this->getJson('/api/events?q=blue', $this->pub)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Jazz Night');
        $this->getJson('/api/events?q=%25', $this->pub)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/events?per_page=5000', $this->pub)->assertStatus(422);
    });

    it('needs the tenant header like every other endpoint', function () {
        $this->getJson('/api/events')->assertStatus(400)->assertJsonPath('error', 'tenant_header_missing');
        $this->getJson('/api/events', ['X-Tenant-ID' => 'nobody'])->assertNotFound()->assertJsonPath('error', 'tenant_not_found');
    });
});

describe('event page', function () {
    it('shows tiers in order with availability, sales status and integer prices', function () {
        $this->tenant->run(fn () => publishedEvent(['slug' => 'sunset', 'title' => 'Sunset'], [['VIP', 12000, 10, 4, 5], ['General', 5000, 100, 0, 0]]));

        $this->getJson('/api/events/sunset', $this->pub)
            ->assertOk()
            ->assertJsonPath('data.title', 'Sunset')
            ->assertJsonPath('data.currency', 'MYR')
            ->assertJsonPath('data.timing', 'upcoming')
            ->assertJsonPath('data.ticket_types.0.name', 'VIP')
            ->assertJsonPath('data.ticket_types.0.price_minor', 12000)
            ->assertJsonPath('data.ticket_types.0.available', 1)
            ->assertJsonPath('data.ticket_types.0.max_per_order', 1) // cannot ask for more than is left
            ->assertJsonPath('data.ticket_types.0.sales_status', 'on_sale')
            ->assertJsonPath('data.ticket_types.1.available', 100)
            ->assertJsonPath('data.ticket_types.1.max_per_order', 10)
            ->assertJsonMissingPath('data.ticket_types.0.sold')
            ->assertJsonMissingPath('data.ticket_types.0.held')
            ->assertJsonMissingPath('data.ticket_types.0.quantity');
    });

    it('reports sold out, not yet on sale and ended tiers', function () {
        $this->tenant->run(function () {
            $event = publishedEvent(['slug' => 'mixed'], [['Gone', 100, 5, 5, 0]]);
            TicketType::factory()->for($event)->create(['name' => 'Soon', 'sales_start_at' => '2027-01-10 00:00:00', 'sort_order' => 5]);
            TicketType::factory()->for($event)->create(['name' => 'Over', 'sales_end_at' => '2026-12-31 00:00:00', 'sort_order' => 6]);
        });

        $statuses = collect($this->getJson('/api/events/mixed', $this->pub)->assertOk()->json('data.ticket_types'))->pluck('sales_status', 'name');

        expect($statuses->all())->toBe(['Gone' => 'sold_out', 'Soon' => 'upcoming', 'Over' => 'ended']);
    });

    it('hides drafts, cancelled and unknown events behind the same 404', function () {
        $this->tenant->run(function () {
            Event::factory()->create(['slug' => 'draft-one']);
            Event::factory()->cancelled()->create(['slug' => 'called-off']);
        });

        foreach (['draft-one', 'called-off', 'does-not-exist'] as $slug) {
            $this->getJson("/api/events/{$slug}", $this->pub)->assertNotFound()->assertJsonPath('error', 'not_found');
        }
    });
});

describe('caching', function () {
    it('serves repeat visits without touching the events tables', function () {
        $this->tenant->run(fn () => publishedEvent(['slug' => 'sunset']));

        $first = sqlDuring(fn () => $this->getJson('/api/events/sunset', $this->pub)->assertOk());
        $second = sqlDuring(fn () => $this->getJson('/api/events/sunset', $this->pub)->assertOk());
        $list1 = sqlDuring(fn () => $this->getJson('/api/events', $this->pub)->assertOk());
        $list2 = sqlDuring(fn () => $this->getJson('/api/events', $this->pub)->assertOk());

        $hits = fn (array $queries) => collect($queries)->filter(fn ($sql) => str_contains($sql, '"events"') || str_contains($sql, '"ticket_types"'))->count();

        expect($hits($first))->toBeGreaterThan(0)->and($hits($second))->toBe(0)
            ->and($hits($list1))->toBeGreaterThan(0)->and($hits($list2))->toBe(0);
    });

    it('uses a constant number of queries however many events are listed', function () {
        $this->tenant->run(function () {
            foreach (range(1, 12) as $i) {
                publishedEvent(['slug' => "e-{$i}", 'starts_at' => '2027-02-01 10:00:00', 'ends_at' => '2027-02-01 14:00:00'], [['A', 100, 10, 0, 0], ['B', 200, 10, 0, 0]]);
            }
        });

        $queries = sqlDuring(fn () => $this->getJson('/api/events?per_page=12', $this->pub)->assertOk()->assertJsonCount(12, 'data'));

        expect(count($queries))->toBeLessThan(6); // tenant lookup + count + page
    });

    it('refreshes at once when an admin changes what visitors see', function () {
        $event = $this->tenant->run(fn () => publishedEvent(['slug' => 'sunset', 'title' => 'Sunset']));
        $tierId = $this->tenant->run(fn () => TicketType::query()->sole()->id);

        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.ticket_types.0.price_minor', 5000);
        $this->getJson('/api/events', $this->pub)->assertJsonPath('meta.total', 1);

        $this->patchJson("/api/admin/ticket-types/{$tierId}", ['price_minor' => 7500], $this->admin)->assertOk();
        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.ticket_types.0.price_minor', 7500);

        $this->patchJson("/api/admin/events/{$event->id}", ['title' => 'Sunset 2'], $this->admin)->assertOk();
        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.title', 'Sunset 2');

        $this->postJson("/api/admin/events/{$event->id}/ticket-types", ['name' => 'Extra', 'price_minor' => 100, 'quantity' => 5], $this->admin)->assertCreated();
        $this->getJson('/api/events/sunset', $this->pub)->assertJsonCount(2, 'data.ticket_types');

        $this->postJson("/api/admin/events/{$event->id}/cancel", [], $this->admin)->assertOk();
        $this->getJson('/api/events/sunset', $this->pub)->assertNotFound();
        $this->getJson('/api/events', $this->pub)->assertJsonPath('meta.total', 0);
    });

    it('shows a newly published event straight away, and drops a deleted one', function () {
        $draft = $this->tenant->run(function () {
            $event = Event::factory()->create(['slug' => 'fresh']);
            TicketType::factory()->for($event)->create();

            return $event;
        });

        $this->getJson('/api/events/fresh', $this->pub)->assertNotFound();
        $this->getJson('/api/events', $this->pub)->assertJsonPath('meta.total', 0);

        $this->postJson("/api/admin/events/{$draft->id}/publish", [], $this->admin)->assertOk();
        $this->getJson('/api/events/fresh', $this->pub)->assertOk();
        $this->getJson('/api/events', $this->pub)->assertJsonPath('meta.total', 1);

        $this->deleteJson("/api/admin/events/{$draft->id}", [], $this->admin)->assertNoContent();
        $this->getJson('/api/events/fresh', $this->pub)->assertNotFound();
        $this->getJson('/api/events', $this->pub)->assertJsonPath('meta.total', 0);
    });

    it('lets availability lag by a few seconds only, then catches up', function () {
        $tierId = $this->tenant->run(function () {
            publishedEvent(['slug' => 'sunset'], [['General', 5000, 100, 0, 0]]);

            return TicketType::query()->sole()->id;
        });

        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.ticket_types.0.available', 100);

        // What a checkout would do: stock changes in the database, not through the admin API.
        $this->tenant->run(fn () => DB::statement('UPDATE ticket_types SET sold = 60 WHERE id = ?', [$tierId]));
        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.ticket_types.0.available', 100); // still cached

        Carbon::setTestNow(now()->addSeconds(config('ticketing.cache.availability_ttl') + 1));
        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.ticket_types.0.available', 40);
    });

    it('flips the sales status on time even while the page is cached', function () {
        $this->tenant->run(function () {
            $event = Event::factory()->published()->create(['slug' => 'sunset']);
            TicketType::factory()->for($event)->create(['sales_start_at' => '2027-01-01 00:01:00']);
        });

        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.ticket_types.0.sales_status', 'upcoming');

        Carbon::setTestNow('2027-01-01 00:02:00'); // well inside the long TTL of the descriptive data
        $this->getJson('/api/events/sunset', $this->pub)->assertJsonPath('data.ticket_types.0.sales_status', 'on_sale');
    });

    it('never mixes the caches of two tenants', function () {
        $this->tenant->run(fn () => publishedEvent(['slug' => 'same-slug', 'title' => 'Tenant A event']));
        $other = provisionTenant('other-org', 'admin@other.test');
        $other->run(fn () => publishedEvent(['slug' => 'same-slug', 'title' => 'Tenant B event']));

        // warm A's cache first, then ask B
        $this->getJson('/api/events/same-slug', $this->pub)->assertJsonPath('data.title', 'Tenant A event');
        $this->getJson('/api/events', $this->pub);

        $this->getJson('/api/events/same-slug', ['X-Tenant-ID' => 'other-org'])->assertJsonPath('data.title', 'Tenant B event');
        $this->getJson('/api/events', ['X-Tenant-ID' => 'other-org'])->assertJsonPath('data.0.title', 'Tenant B event')->assertJsonPath('meta.total', 1);

        // and an admin change in B does not flush A's cache entries (different version key)
        $bAdmin = tenantHeaders($other);
        $idB = $other->run(fn () => Event::query()->sole()->id);
        $this->patchJson("/api/admin/events/{$idB}", ['title' => 'B renamed'], $bAdmin)->assertOk();
        $this->getJson('/api/events/same-slug', ['X-Tenant-ID' => 'other-org'])->assertJsonPath('data.title', 'B renamed');
        $this->getJson('/api/events/same-slug', $this->pub)->assertJsonPath('data.title', 'Tenant A event');
    });
});

describe('abuse protection', function () {
    it('throttles one visitor per tenant', function () {
        config(['ticketing.rate_limits.storefront_per_minute' => 3]);
        $this->tenant->run(fn () => publishedEvent(['slug' => 'sunset']));

        foreach (range(1, 3) as $_) {
            $this->getJson('/api/events/sunset', $this->pub)->assertOk();
        }
        $this->getJson('/api/events/sunset', $this->pub)->assertStatus(429)->assertJsonPath('error', 'too_many_requests');
    });
});
