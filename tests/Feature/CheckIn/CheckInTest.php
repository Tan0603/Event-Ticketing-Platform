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
    $this->scan = fn (string $code, array $extra = [], ?array $headers = null) => $this->postJson('/api/check-in', ['code' => $code] + $extra, $headers ?? $this->door);
});

describe('scanning a ticket', function () {
    it('admits a valid ticket and records who and when', function () {
        [$code] = ($this->sell)();
        Carbon::setTestNow('2027-03-01 10:30:00');
        $door = tenantHeaders($this->tenant, UserRole::DoorStaff); // the 12 h token from setup has expired

        ($this->scan)($code, [], $door)->assertOk()
            ->assertJsonPath('data.code', $code)
            ->assertJsonPath('data.status', 'used')
            ->assertJsonPath('data.ticket_type', 'General')
            ->assertJsonPath('data.buyer_name', 'Aisha Rahman')
            ->assertJsonPath('data.event.title', 'Sunset Fest')
            ->assertJsonPath('data.checked_in_at', fn ($at) => str_contains($at, '2027-03-01T18:30:00+08:00'));

        $ticket = $this->tenant->run(fn () => Ticket::query()->where('code', $code)->sole());
        expect($ticket->status)->toBe(TicketStatus::Used)
            ->and($ticket->checked_in_by)->not->toBeNull()
            ->and($ticket->checked_in_at)->not->toBeNull();
    });

    it('lets admins scan too, and tolerates lower case and spaces in the code', function () {
        [$code] = ($this->sell)();

        ($this->scan)('  '.strtolower($code).' ', [], tenantHeaders($this->tenant, UserRole::Admin))->assertOk()->assertJsonPath('data.code', $code);
    });

    it('admits a ticket only once: the second scan is refused with the first scan time', function () {
        [$code] = ($this->sell)();

        ($this->scan)($code)->assertOk();
        ($this->scan)($code)->assertStatus(409)->assertJsonPath('error', 'already_checked_in');

        expect($this->tenant->run(fn () => DB::table('tickets')->where('code', $code)->where('status', 'used')->count()))->toBe(1);
    });

    it('lets exactly one of many racing scans win, at the SQL level', function () {
        [$code] = ($this->sell)();

        // The same conditional UPDATE the action runs: every repeat finds nothing to update.
        $wins = $this->tenant->run(function () use ($code) {
            $results = [];
            foreach (range(1, 5) as $_) {
                $results[] = count(DB::select("UPDATE tickets SET status = 'used', checked_in_at = now() WHERE code = ? AND status = 'valid' RETURNING id", [$code]));
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

    it('refuses a ticket for another event when the scanner names its gate', function () {
        [$code] = ($this->sell)();
        $other = $this->tenant->run(fn () => Event::factory()->published()->create()->id);

        ($this->scan)($code, ['event_id' => $other])->assertStatus(409)->assertJsonPath('error', 'wrong_event');
        ($this->scan)($code, ['event_id' => $this->event->id])->assertOk();
    });
});

describe('access and validation', function () {
    it('requires a login', function () {
        $this->postJson('/api/check-in', ['code' => 'ABC'], $this->pub)->assertUnauthorized();
    });

    it('validates the body', function () {
        $this->postJson('/api/check-in', [], $this->door)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->postJson('/api/check-in', ['code' => 'X', 'event_id' => 'abc'], $this->door)->assertStatus(422);
    });

    it('does not let tenant B scan tenant A tickets', function () {
        [$code] = ($this->sell)();
        $other = provisionTenant('other-org', 'admin@other.test');

        ($this->scan)($code, [], tenantHeaders($other, UserRole::DoorStaff))->assertNotFound()->assertJsonPath('error', 'ticket_not_found');
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
