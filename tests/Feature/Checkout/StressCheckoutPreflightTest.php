<?php

use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 10:00:00');
    Http::preventStrayRequests();
    Http::fake();
    $this->tenant = provisionTenant('acme-events');

    $this->tier = fn (array $tier = [], ?Event $event = null) => $this->tenant->run(
        fn () => TicketType::factory()->for($event ?? Event::factory()->published()->create(['starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']))
            ->create($tier + ['quantity' => 50, 'max_per_order' => 5]),
    );

    $this->stress = function (int $tierId, int $requests = 3): array {
        $code = Artisan::call('stress:checkout', ['tenant' => 'acme-events', 'ticket_type' => $tierId, '--requests' => $requests]);

        return [$code, Artisan::output()];
    };
});

it('refuses a tier whose sales have not started, explains the fix and sends no request', function () {
    $tier = ($this->tier)(['sales_start_at' => '2027-12-01 10:00:00']);

    [$code, $output] = ($this->stress)($tier->id);

    expect($code)->toBe(1)
        ->and($output)->toContain("Ticket type {$tier->id} is not on sale yet")
        ->and($output)->toContain("PATCH /api/admin/ticket-types/{$tier->id}")
        ->and($output)->toContain('sales_start_at');
    Http::assertNothingSent();
});

it('refuses a tier whose sales window has closed', function () {
    $tier = ($this->tier)(['sales_start_at' => '2026-12-01 10:00:00', 'sales_end_at' => '2026-12-31 10:00:00']);

    [$code, $output] = ($this->stress)($tier->id);

    expect($code)->toBe(1)->and($output)->toContain('sales have ended');
    Http::assertNothingSent();
});

it('refuses a tier of a draft event', function () {
    $event = $this->tenant->run(fn () => Event::factory()->create(['starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']));
    $tier = ($this->tier)([], $event);

    [$code, $output] = ($this->stress)($tier->id);

    expect($code)->toBe(1)->and($output)->toContain('draft event')->and($output)->toContain("/api/admin/events/{$event->id}/publish");
    Http::assertNothingSent();
});

it('refuses a tier of an event that already ended', function () {
    $event = $this->tenant->run(fn () => Event::factory()->published()->create(['starts_at' => '2026-11-01 11:00:00', 'ends_at' => '2026-11-01 15:00:00']));
    $tier = ($this->tier)([], $event);

    [$code, $output] = ($this->stress)($tier->id);

    expect($code)->toBe(1)->and($output)->toContain('already ended');
    Http::assertNothingSent();
});

it('rejects an unknown ticket type without sending requests', function () {
    [$code, $output] = ($this->stress)(999999);

    expect($code)->toBe(2)->and($output)->toContain('Unknown ticket type');
    Http::assertNothingSent();
});

it('reports the remaining stock and the expected outcome when the tier is on sale', function () {
    $tier = ($this->tier)(['quantity' => 2]);

    [, $output] = ($this->stress)($tier->id, 5);

    expect($output)->toContain('Remaining: 2, so expect 2 created and 3 insufficient_stock');
    Http::assertSentCount(5);
});
