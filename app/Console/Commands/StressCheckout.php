<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Central\Tenant;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TicketType;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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

        /** @var array{slug: string, name: string, quantity: int, sold: int, held: int}|null $before */
        $before = $tenant->run(function () use ($ticketTypeId): ?array {
            $tier = TicketType::query()->with('event')->find($ticketTypeId);

            return $tier === null ? null : [
                'slug' => $tier->event->slug,
                'name' => $tier->name,
                'quantity' => $tier->quantity,
                'sold' => $tier->sold,
                'held' => $tier->held,
            ];
        });

        if ($before === null) {
            $this->error('Unknown ticket type.');

            return self::INVALID;
        }

        $ipLimit = (int) config('ticketing.rate_limits.checkout_per_minute_ip');
        if ($ipLimit > 0 && $requests > $ipLimit) {
            $this->warn("{$requests} requests exceed the per-IP limit of {$ipLimit}/min: some will be answered 429.");
        }

        $this->info("Tenant {$tenant->id} · \"{$before['name']}\" · quantity {$before['quantity']} (sold {$before['sold']}, held {$before['held']})");
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

        /** @var array{sold: int, held: int, quantity: int, pending_lines: int} $after */
        $after = $tenant->run(function () use ($ticketTypeId): array {
            $tier = TicketType::query()->findOrFail($ticketTypeId);

            return [
                'sold' => $tier->sold,
                'held' => $tier->held,
                'quantity' => $tier->quantity,
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
        $expectedCreated = min($requests, intdiv(max($remaining, 0), $quantity));
        $this->line("Stock available before: {$remaining} -> expected {$expectedCreated} orders, got {$created}.");

        $this->{$ok ? 'info' : 'error'}($ok ? 'No oversell.' : 'INVARIANT BROKEN.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
