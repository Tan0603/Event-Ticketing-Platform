<?php

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Ticket;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->door = tenantHeaders($this->tenant, UserRole::DoorStaff);
    $this->pub = ['X-Tenant-ID' => 'acme-events'];

    [$this->event, $this->type] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'title' => 'Sunset Fest', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);

        return [$event, TicketType::factory()->for($event)->create(['name' => 'General', 'price_minor' => 5000, 'quantity' => 20, 'max_per_order' => 10])];
    });

    // Buys and pays for $quantity tickets; returns their codes.
    $this->sell = function (int $quantity = 1, string $slug = 'sunset', ?int $typeId = null) {
        $reference = $this->postJson("/api/events/{$slug}/checkout", [
            'buyer_name' => 'Aisha Rahman', 'buyer_email' => 'aisha@example.com',
            'items' => [['ticket_type_id' => $typeId ?? $this->type->id, 'quantity' => $quantity]],
        ], $this->pub)->assertCreated();
        $body = json_encode(['id' => 'evt_'.uniqid(), 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($reference->json('data.reference')), 'amount' => $reference->json('data.total_minor'), 'currency' => 'MYR']);
        $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
        ], $body)->assertOk();

        return $this->tenant->run(fn () => Ticket::query()->orderByDesc('id')->limit($quantity)->pluck('code')->all());
    };
    $this->scan = fn (string $code, array $extra = [], ?array $headers = null, ?int $eventId = null) => $this->postJson('/api/admin/events/'.($eventId ?? $this->event->id).'/check-ins', ['code' => $code] + $extra, $headers ?? $this->door);
});

describe('scanning a ticket', function () {
    it('admits a valid ticket and records who and when', function () {
        [$code] = ($this->sell)();
        Carbon::setTestNow('2027-03-01 10:30:00');
        $door = tenantHeaders($this->tenant, UserRole::DoorStaff); // the 12 h token from setup has expired

        ($this->scan)($code, [], $door)->assertOk()
            ->assertJsonPath('data.code', $code)
            ->assertJsonPath('data.status', 'checked_in')
            ->assertJsonPath('data.ticket_type', 'General')
            ->assertJsonPath('data.buyer_name', 'Aisha Rahman')
            ->assertJsonPath('data.event.title', 'Sunset Fest')
            ->assertJsonPath('data.checked_in_at', fn ($at) => str_contains($at, '2027-03-01T18:30:00+08:00'));

        $ticket = $this->tenant->run(fn () => Ticket::query()->where('code', $code)->sole());
        expect($ticket->status)->toBe(TicketStatus::CheckedIn)
            ->and($ticket->checked_in_by)->not->toBeNull()
            ->and($ticket->checked_in_at)->not->toBeNull();
    });

    it('lets admins scan too, and tolerates lower case and spaces in the code', function () {
        [$code] = ($this->sell)();

        ($this->scan)('  '.strtolower($code).' ', [], tenantHeaders($this->tenant, UserRole::Admin))->assertOk()->assertJsonPath('data.code', $code);
    });

    it('records the gate, and refuses a repeat scan saying when and by whom', function () {
        [$code] = ($this->sell)();
        Carbon::setTestNow('2027-03-01 10:30:00');
        $first = tenantHeaders($this->tenant, UserRole::DoorStaff); // its name is "Door Staff"

        ($this->scan)($code, ['gate_name' => 'Gate A'], $first)->assertOk()->assertJsonPath('data.gate_name', 'Gate A');
        ($this->scan)($code, ['gate_name' => 'Gate B'], $first)->assertStatus(409)
            ->assertJsonPath('error', 'already_checked_in')
            ->assertJsonPath('checked_in_at', fn ($at) => str_contains($at, '2027-03-01T18:30:00+08:00'))
            ->assertJsonPath('checked_in_by.name', fn ($name) => $name !== null && $name !== '')
            ->assertJsonPath('gate_name', 'Gate A'); // the first gate keeps the credit

        expect($this->tenant->run(fn () => DB::table('tickets')->where('code', $code)->where('status', 'checked_in')->count()))->toBe(1);
    });

    it('lets exactly one of many racing scans win, at the SQL level', function () {
        [$code] = ($this->sell)();

        // The same conditional UPDATE the action runs: every repeat finds nothing to update.
        $wins = $this->tenant->run(function () use ($code) {
            $results = [];
            foreach (range(1, 5) as $_) {
                $results[] = count(DB::select("UPDATE tickets SET status = 'checked_in', checked_in_at = now() WHERE code = ? AND status = 'valid' RETURNING id", [$code]));
            }

            return $results;
        });

        expect($wins)->toBe([1, 0, 0, 0, 0]);
    });

    it('answers 404 for a code that does not exist', function () {
        ($this->scan)('NOSUCHCODE0000000000')->assertNotFound()->assertJsonPath('error', 'ticket_not_found');
    });

    it('refuses a void ticket', function () {
        [$code] = ($this->sell)();
        $this->tenant->run(fn () => DB::table('tickets')->where('code', $code)->update(['status' => TicketStatus::Void->value]));

        ($this->scan)($code)->assertStatus(409)->assertJsonPath('error', 'ticket_void');
    });

    it('refuses a ticket for a cancelled event', function () {
        [$code] = ($this->sell)();
        $this->tenant->run(fn () => DB::table('events')->update(['status' => EventStatus::Cancelled->value]));

        ($this->scan)($code)->assertStatus(409)->assertJsonPath('error', 'event_cancelled');
        expect($this->tenant->run(fn () => Ticket::query()->where('code', $code)->value('status')))->toBe(TicketStatus::Valid);
    });

    it('treats a ticket of another event as not found, and leaves it valid', function () {
        [$code] = ($this->sell)();
        $other = $this->tenant->run(fn () => Event::factory()->published()->create()->id);

        ($this->scan)($code, [], null, $other)->assertNotFound()->assertJsonPath('error', 'ticket_not_found');
        expect($this->tenant->run(fn () => Ticket::query()->where('code', $code)->value('status')))->toBe(TicketStatus::Valid);
        ($this->scan)($code)->assertOk();
    });
});

describe('access and validation', function () {
    it('requires a login', function () {
        $this->postJson("/api/admin/events/{$this->event->id}/check-ins", ['code' => 'ABC'], $this->pub)->assertUnauthorized();
        $this->getJson("/api/admin/events/{$this->event->id}/check-ins/stats", $this->pub)->assertUnauthorized();
    });

    it('validates the body', function () {
        $url = "/api/admin/events/{$this->event->id}/check-ins";
        $this->postJson($url, [], $this->door)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->postJson($url, ['code' => 'X', 'gate_name' => str_repeat('g', 61)], $this->door)->assertStatus(422);
        $this->postJson('/api/admin/events/999999/check-ins', ['code' => 'X'], $this->door)->assertNotFound();
    });

    it('does not let tenant B scan tenant A tickets', function () {
        [$code] = ($this->sell)();
        $other = provisionTenant('other-org', 'admin@other.test');

        ($this->scan)($code, [], tenantHeaders($other, UserRole::DoorStaff))->assertNotFound()->assertJsonPath('error', 'not_found'); // the event id does not exist in tenant B at all
        expect($this->tenant->run(fn () => Ticket::query()->where('code', $code)->value('status')))->toBe(TicketStatus::Valid);
    });

    it('rate limits a staff member that scans too fast', function () {
        config(['ticketing.rate_limits.checkin_per_minute' => 3]);

        foreach (range(1, 3) as $_) {
            ($this->scan)('NOSUCHCODE0000000000')->assertNotFound();
        }
        ($this->scan)('NOSUCHCODE0000000000')->assertStatus(429);
    });
});

describe('door stats', function () {
    it('counts total, checked in and remaining, in total and per ticket type', function () {
        $vip = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['name' => 'VIP', 'price_minor' => 9000, 'quantity' => 5, 'max_per_order' => 5, 'sort_order' => 2])->id);
        $general = ($this->sell)(3);
        ($this->sell)(2, 'sunset', $vip);
        ($this->scan)($general[0])->assertOk();
        $this->tenant->run(fn () => DB::table('tickets')->where('code', $general[1])->update(['status' => TicketStatus::Void->value]));

        $this->getJson("/api/admin/events/{$this->event->id}/check-ins/stats", $this->door)->assertOk()
            ->assertJsonPath('data.total', 4)          // 5 sold, 1 void is not an admission any more
            ->assertJsonPath('data.checked_in', 1)
            ->assertJsonPath('data.remaining', 3)
            ->assertJsonPath('data.void', 1)
            ->assertJsonPath('data.by_ticket_type.0.name', 'General')
            ->assertJsonPath('data.by_ticket_type.0.total', 2)
            ->assertJsonPath('data.by_ticket_type.0.checked_in', 1)
            ->assertJsonPath('data.by_ticket_type.0.remaining', 1)
            ->assertJsonPath('data.by_ticket_type.1.name', 'VIP')
            ->assertJsonPath('data.by_ticket_type.1.total', 2)
            ->assertJsonPath('data.by_ticket_type.1.remaining', 2);
    });

    it('is visible to door staff and admins, and never shows another tenant', function () {
        ($this->sell)(2);
        $other = provisionTenant('other-org', 'admin@other.test');
        $otherEvent = $other->run(fn () => Event::factory()->published()->create()->id);
        $secondOfA = $this->tenant->run(fn () => Event::factory()->published()->create()->id); // id 2 exists only in tenant A

        $this->getJson("/api/admin/events/{$this->event->id}/check-ins/stats", tenantHeaders($this->tenant, UserRole::Admin))->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson("/api/admin/events/{$secondOfA}/check-ins/stats", tenantHeaders($other))->assertNotFound(); // not B's event
        $this->getJson("/api/admin/events/{$otherEvent}/check-ins/stats", tenantHeaders($other))->assertOk()->assertJsonPath('data.total', 0);
    });
});

describe('attendee list', function () {
    it('lists attendees page by page, filtered by ticket type and check-in status', function () {
        $vip = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['name' => 'VIP', 'price_minor' => 9000, 'quantity' => 5, 'max_per_order' => 5, 'sort_order' => 2])->id);
        $general = ($this->sell)(3);
        ($this->sell)(2, 'sunset', $vip);
        ($this->scan)($general[0], ['gate_name' => 'Gate A'])->assertOk();
        $admin = tenantHeaders($this->tenant);
        $url = "/api/admin/events/{$this->event->id}/attendees";

        $this->getJson($url.'?per_page=2', $admin)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
        $this->getJson($url."?ticket_type_id={$vip}", $admin)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.ticket_type.name', 'VIP');
        $this->getJson($url.'?status=checked_in', $admin)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ticket_code', $general[0])
            ->assertJsonPath('data.0.buyer_email', 'aisha@example.com')
            ->assertJsonPath('data.0.gate_name', 'Gate A')
            ->assertJsonPath('data.0.checked_in_by', fn ($name) => $name !== null);
        $this->getJson($url.'?status=valid', $admin)->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson($url.'?status=nonsense', $admin)->assertStatus(422);
    });

    it('runs the same queries for any number of attendees', function () {
        $admin = tenantHeaders($this->tenant);
        ($this->sell)(2);
        $count = function () use ($admin) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->tenant->run(fn () => $this->getJson("/api/admin/events/{$this->event->id}/attendees", $admin)->assertOk());
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // warm-up: the first call also touches the token
        $few = $count();
        ($this->sell)(8);

        expect($count())->toBe($few);
    });

    it('is for admins only', function () {
        $this->getJson("/api/admin/events/{$this->event->id}/attendees", $this->door)->assertForbidden();
    });
});
