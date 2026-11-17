<?php

namespace App\Console\Commands;

use App\Actions\Auth\LoginStaff;
use App\Enums\TicketStatus;
use App\Models\Central\Tenant;
use App\Models\Tenant\Ticket;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fires many simultaneous real HTTP scans of ONE ticket code and checks that exactly one was admitted:
 * the proof that "UPDATE ... WHERE status = 'valid' RETURNING" leaves no window for a double entry.
 */
class StressCheckIn extends Command
{
    protected $signature = 'stress:checkin
        {tenant : Tenant id}
        {code : Code of a ticket that is still valid}
        {email : Email of an admin or door staff of that tenant}
        {password : Their password}
        {--requests=50 : Number of simultaneous scans}
        {--url=http://nginx : Base URL of the running API (from inside the app container)}';

    protected $description = 'Scan one ticket from many requests at once and verify it is admitted exactly once';

    public function handle(LoginStaff $login): int
    {
        $tenant = Tenant::query()->find((string) $this->argument('tenant'));
        if ($tenant === null) {
            $this->error('Unknown tenant.');

            return self::INVALID;
        }

        $code = strtoupper((string) $this->argument('code'));
        $ticket = $tenant->run(fn () => Ticket::query()->where('code', $code)->first(['event_id', 'status']));
        if ($ticket === null || $ticket->status !== TicketStatus::Valid) {
            $this->error('That ticket does not exist or is not valid any more (nothing to race for).');

            return self::INVALID;
        }

        $token = $tenant->run(fn () => $login->execute((string) $this->argument('email'), (string) $this->argument('password'))->plainTextToken);

        $eventId = $ticket->event_id;
        $requests = max(2, (int) $this->option('requests'));
        $url = rtrim((string) $this->option('url'), '/')."/api/admin/events/{$eventId}/check-ins";
        $this->info("Scanning {$code} {$requests} times at once ...");

        /** @var array<string, Response|\Throwable> $responses */
        $responses = Http::pool(function (Pool $pool) use ($requests, $url, $tenant, $token, $code) {
            $calls = [];
            for ($i = 1; $i <= $requests; $i++) {
                $calls[] = $pool->as("s{$i}")->withToken($token)->withHeaders(['X-Tenant-ID' => $tenant->id, 'Accept' => 'application/json'])->timeout(60)->post($url, ['code' => $code]);
            }

            return $calls;
        }, concurrency: $requests);

        $outcomes = [];
        foreach ($responses as $response) {
            $key = $response instanceof Response ? $response->status().($response->status() >= 400 ? ' '.($response->json('error') ?? '?') : ' admitted') : 'connection error';
            $outcomes[$key] = ($outcomes[$key] ?? 0) + 1;
        }
        ksort($outcomes);
        $this->table(['outcome', 'count'], collect($outcomes)->map(fn ($n, $k) => [$k, $n])->values()->all());

        $admitted = $outcomes['200 admitted'] ?? 0;
        $ok = $admitted === 1;
        $this->{$ok ? 'info' : 'error'}($ok ? 'Admitted exactly once.' : "INVARIANT BROKEN: admitted {$admitted} times.");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
