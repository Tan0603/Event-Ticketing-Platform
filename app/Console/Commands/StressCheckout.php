<?php

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Enums\EventTiming;
use App\Enums\OrderStatus;
use App\Enums\SalesStatus;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TicketType;
use App\Support\TenantTime;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fires many simultaneous real HTTP checkouts at one ticket type and then audits the database:
 * however the requests interleave, sold + held must never exceed the quantity and every counted hold must be
 * backed by exactly one pending order line. This is the proof that the atomic UPDATE approach does not oversell.
 */
class StressCheckout extends Command
{
    protected $signature = 'stress:checkout
        {tenant : Tenant id}
        {ticket_type : Ticket type id}
        {--requests=200 : Number of checkout requests}
        {--quantity=1 : Tickets per request}
        {--concurrency=50 : Requests in flight at the same time}
        {--url=http://nginx : Base URL of the running API (from inside the app container)}';

    protected $description = 'Hammer checkout with concurrent requests and verify nothing was oversold';

    public function handle(): int
    {
        $tenant = Tenant::query()->find($this->argument('tenant'));
        if ($tenant === null) {
            $this->error('Unknown tenant.');

            return self::INVALID;
        }

        $requests = max(1, (int) $this->option('requests'));
        $quantity = max(1, (int) $this->option('quantity'));
        $concurrency = max(1, (int) $this->option('concurrency'));
        $ticketTypeId = (int) $this->argument('ticket_type');

        /** @var array{slug: string, name: string, quantity: int, sold: int, held: int, capacity: int|null, reserved: int, problem: string|null}|null $before */
        $before = $tenant->run(function () use ($ticketTypeId): ?array {
            $tier = TicketType::query()->with('event')->find($ticketTypeId);

            return $tier === null ? null : [
                'slug' => $tier->event->slug,
                'name' => $tier->name,
                'quantity' => $tier->quantity,
                'sold' => $tier->sold,
                'held' => $tier->held,
                'capacity' => $tier->event->capacity,
                'reserved' => $tier->event->reserved,
                'problem' => $this->notOnSale($tier),
            ];
        });

        if ($before === null) {
            $this->error('Unknown ticket type.');

            return self::INVALID;
        }

        if ($before['problem'] !== null) {
            $this->error($before['problem']);

            return self::FAILURE;
        }

        $ipLimit = (int) config('ticketing.rate_limits.checkout_per_minute_ip');
        if ($ipLimit > 0 && $requests > $ipLimit) {
            $this->warn("{$requests} requests exceed the per-IP limit of {$ipLimit}/min: some will be answered 429.");
        }

        if ($before['capacity'] !== null) {
            $this->line("Event shared capacity {$before['capacity']} (reserved {$before['reserved']}).");
        }
        $this->info("Tenant {$tenant->id} · \"{$before['name']}\" · quantity {$before['quantity']} (sold {$before['sold']}, held {$before['held']})");
        $left = max(0, $before['quantity'] - $before['sold'] - $before['held']);
        if ($before['capacity'] !== null) {
            $left = min($left, max(0, $before['capacity'] - $before['reserved']));
        }
        $expectCreated = min($requests, intdiv($left, $quantity));
        $this->info("On sale. Remaining: {$left}, so expect {$expectCreated} created and ".($requests - $expectCreated).' insufficient_stock.');
        $this->info("Sending {$requests} checkouts of {$quantity} ticket(s), {$concurrency} at a time ...");

        $url = rtrim((string) $this->option('url'), '/')."/api/events/{$before['slug']}/checkout";
        $run = Str::lower(Str::random(6));
        $started = microtime(true);

        /** @var array<string, Response|\Throwable> $responses */
        $responses = Http::pool(function (Pool $pool) use ($requests, $quantity, $ticketTypeId, $tenant, $url, $run) {
            $calls = [];
            for ($i = 1; $i <= $requests; $i++) {
                $calls[] = $pool->as("r{$i}")
                    ->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => "stress-{$run}-{$i}", 'Accept' => 'application/json'])
                    ->timeout(60)
                    ->post($url, [
                        'buyer_name' => "Stress Buyer {$i}",
                        'buyer_email' => "stress-{$run}-{$i}@example.test",
                        'items' => [['ticket_type_id' => $ticketTypeId, 'quantity' => $quantity]],
                    ]);
            }

            return $calls;
        }, concurrency: $concurrency);

        $elapsed = microtime(true) - $started;

        $outcomes = [];
        foreach ($responses as $response) {
            $key = $response instanceof Response
                ? $response->status().($response->status() >= 400 ? ' '.($response->json('error') ?? '?') : ' created')
                : 'connection error';
            $outcomes[$key] = ($outcomes[$key] ?? 0) + 1;
        }
        ksort($outcomes);

        $this->table(['outcome', 'count'], collect($outcomes)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line(sprintf('%d requests in %.2fs (%.0f req/s)', $requests, $elapsed, $requests / max($elapsed, 0.001)));

        $created = collect($responses)->filter(fn ($r) => $r instanceof Response && $r->status() === 201)->count();

        /** @var array{sold: int, held: int, quantity: int, pending_lines: int, reserved: int, tiers_taken: int} $after */
        $after = $tenant->run(function () use ($ticketTypeId): array {
            $tier = TicketType::query()->findOrFail($ticketTypeId);

            return [
                'sold' => $tier->sold,
                'held' => $tier->held,
                'quantity' => $tier->quantity,
                'reserved' => (int) Event::query()->whereKey($tier->event_id)->value('reserved'),
                'tiers_taken' => (int) TicketType::query()->where('event_id', $tier->event_id)->sum(DB::raw('sold + held')),
                'pending_lines' => (int) OrderItem::query()
                    ->where('ticket_type_id', $ticketTypeId)
                    ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Pending))
                    ->sum('quantity'),
            ];
        });

        $heldGain = $after['held'] - $before['held'];
        $checks = [
            ['sold + held <= quantity' => [$after['sold'] + $after['held'], '<= '.$after['quantity'], $after['quantity'] >= $after['sold'] + $after['held']]],
            ['held grew by exactly the orders created' => [$heldGain, '= '.($created * $quantity), $heldGain === $created * $quantity]],
            ['held = tickets in pending orders' => [$after['held'], '= '.$after['pending_lines'], $after['held'] === $after['pending_lines']]],
        ];

        if ($before['capacity'] !== null) {
            $checks[] = ['event reserved <= event capacity' => [$after['reserved'], '<= '.$before['capacity'], $after['reserved'] <= $before['capacity']]];
            $checks[] = ['event reserved = sold + held of its tiers' => [$after['reserved'], '= '.$after['tiers_taken'], $after['reserved'] === $after['tiers_taken']]];
        }

        $ok = true;
        $rows = [];
        foreach ($checks as $check) {
            foreach ($check as $label => [$actual, $expected, $pass]) {
                $ok = $ok && $pass;
                $rows[] = [$pass ? 'PASS' : 'FAIL', $label, "{$actual} ({$expected})"];
            }
        }
        $this->table(['', 'invariant', 'value'], $rows);

        $remaining = $before['quantity'] - $before['sold'] - $before['held'];
        if ($before['capacity'] !== null) {
            $remaining = min($remaining, $before['capacity'] - $before['reserved']); // the event's shared capacity caps the tier
        }
        $expectedCreated = min($requests, intdiv(max($remaining, 0), $quantity));
        $this->line("Stock available before: {$remaining} -> expected {$expectedCreated} orders, got {$created}.");

        $this->{$ok ? 'info' : 'error'}($ok ? 'No oversell.' : 'INVARIANT BROKEN.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Why checkout would answer 409 to every request, with the fix; null when the tier is open for sale. Uses the
     * same status rule as the storefront and checkout (SalesStatus). Runs inside the tenant.
     */
    private function notOnSale(TicketType $tier): ?string
    {
        $id = $tier->id;
        $event = $tier->event;
        $fmt = fn (?CarbonInterface $t): string => $t === null ? 'not set' : $t->setTimezone(TenantTime::timezone())->format('Y-m-d H:i');

        if ($event->status !== EventStatus::Published || $event->timing() === EventTiming::Ended) {
            return match (true) {
                $event->status === EventStatus::Draft => "Ticket type {$id} belongs to a draft event (not published). Publish it first: POST /api/admin/events/{$event->id}/publish",
                $event->status === EventStatus::Cancelled => "Ticket type {$id} belongs to a cancelled event. Use another ticket type or a published event.",
                default => "Ticket type {$id} belongs to an event that already ended (ends_at {$fmt($event->ends_at)}). Use an upcoming event.",
            };
        }

        return match ($tier->salesStatus()) {
            SalesStatus::Scheduled => "Ticket type {$id} is not on sale yet (sales_start_at {$fmt($tier->sales_start_at)}). Open it first: PATCH /api/admin/ticket-types/{$id} {\"sales_start_at\": null}",
            SalesStatus::Ended => "Ticket type {$id} sales have ended (sales_end_at {$fmt($tier->effectiveSalesEnd())}). Extend it first: PATCH /api/admin/ticket-types/{$id} {\"sales_end_at\": null}",
            SalesStatus::Locked => "Ticket type {$id} is locked until \"{$tier->unlocksAfter?->name}\" sells out or ends. Unlock it first: PATCH /api/admin/ticket-types/{$id} {\"unlocks_after_ticket_type_id\": null}",
            default => null, // on sale (a sold-out tier is still a valid run: every request is answered insufficient_stock)
        };
    }
}
