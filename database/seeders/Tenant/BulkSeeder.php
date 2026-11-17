<?php

namespace Database\Seeders\Tenant;

use App\Domain\Money\FeeCalculator;
use App\Enums\UserRole;
use App\Models\Tenant\Event;
use App\Models\Tenant\User;
use App\Support\StorefrontCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A realistic LOAD of data for ONE tenant (spec section 18): tens of events (past, live, upcoming), 3-5 ticket types
 * each, tens of thousands of orders in every status, the tickets of the paid ones, refunds, and check-ins for about
 * 80 % of the tickets of past events.
 *
 * It is meant for measuring queries, indexes, reports and caches, so it writes in bulk: orders and order lines in
 * chunks of a thousand rows, everything else (tickets, refunds, check-ins, stock counters) as set-based SQL
 * inside PostgreSQL. It does NOT go through the checkout actions (that would be 30 000 transactions): the rules
 * those actions enforce are applied here directly. Money still comes from FeeCalculator, and the stock counters are
 * RECOMPUTED from the generated orders at the end, so `sold`, `held`, the tickets and the CHECK constraints agree.
 * Order statuses are written directly, which production code never does (OrderStateMachine does).
 *
 * Run inside tenant context: `php artisan demo:seed-bulk {tenant}`.
 */
class BulkSeeder extends Seeder
{
    public const SLUG_PREFIX = 'bulk-';

    private const CHUNK = 1000;

    /** Order outcomes in per mille. The remainder (up to 1000) is paid. */
    private const REFUNDED = 40;

    private const EXPIRED = 100;

    private const FAILED = 60;

    private const REFUND_PENDING = 10;

    /** @var array<int, string> */
    private const ADJECTIVES = ['Neon', 'Golden', 'Midnight', 'Sunrise', 'Electric', 'Velvet', 'Crimson', 'Silver', 'Wild', 'Hidden', 'Urban', 'Coastal'];

    /** @var array<int, string> */
    private const NOUNS = ['Fest', 'Nights', 'Live', 'Sessions', 'Carnival', 'Summit', 'Showcase', 'Jam', 'Gala', 'Weekender'];

    /** @var array<int, array{0: string, 1: int}> tier name and base price in minor units */
    private const TIERS = [['Early Bird', 2900], ['General Admission', 4900], ['Premium', 8900], ['VIP', 15900], ['Backstage', 29900]];

    public function __construct(
        private readonly int $events = 40,
        private readonly int $orders = 30000,
        private readonly int $seed = 2026,
    ) {}

    public function run(): void
    {
        if (Event::query()->where('slug', 'like', self::SLUG_PREFIX.'%')->exists()) {
            $this->command->warn('Bulk data is already there; nothing to do.');

            return;
        }

        mt_srand($this->seed);
        $started = microtime(true);

        DB::transaction(function (): void {
            $events = $this->createEvents();
            $this->createOrders($events);
            $this->createTickets();
            $this->createRefunds();
            $this->recomputeStock();
            $this->createCheckIns();
        });

        DB::statement('ANALYZE');
        StorefrontCache::flush();

        $counts = DB::selectOne('SELECT (SELECT COUNT(*) FROM events) AS events, (SELECT COUNT(*) FROM orders) AS orders, (SELECT COUNT(*) FROM tickets) AS tickets');
        $this->command->info(sprintf(
            'Seeded %d events, %d orders, %d tickets in %.1f s. Door staff: door@demo.test / password',
            $counts->events, $counts->orders, $counts->tickets, microtime(true) - $started,
        ));
    }

    /**
     * Events with their ticket types. Returns the ones that take orders.
     *
     * @return array<int, array{id: int, start: int, end: int, phase: string, tiers: array<int, array{id: int, price: int}>}>
     */
    private function createEvents(): array
    {
        $now = CarbonImmutable::now();
        $sellable = [];

        for ($i = 1; $i <= $this->events; $i++) {
            $roll = mt_rand(1, 100);
            // ~40 % past, ~4 % live now, the rest upcoming; ~5 % drafts and ~5 % cancelled sit outside the sales.
            [$phase, $start] = match (true) {
                $roll <= 40 => ['past', $now->subDays(mt_rand(5, 110))->setTime(mt_rand(12, 20), 0)],
                $roll <= 44 => ['live', $now->subHour()],
                default => ['upcoming', $now->addDays(mt_rand(1, 120))->setTime(mt_rand(12, 20), 0)],
            };
            $end = $start->addHours($phase === 'live' ? 4 : 3);
            $status = mt_rand(1, 100) <= 5 ? 'draft' : (mt_rand(1, 100) <= 5 ? 'cancelled' : 'published');
            $title = self::ADJECTIVES[array_rand(self::ADJECTIVES)].' '.self::NOUNS[array_rand(self::NOUNS)];

            $eventId = DB::table('events')->insertGetId([
                'title' => $title,
                'slug' => self::SLUG_PREFIX.Str::slug($title).'-'.$i,
                'description' => "{$title}: live music, food and good company.",
                'venue_name' => 'Hall '.mt_rand(1, 9).', Bukit Jalil',
                'venue_address' => 'Jalan '.mt_rand(1, 99).', 57000 Kuala Lumpur',
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => $status,
                'published_at' => $status === 'draft' ? null : $start->subDays(60),
                'cancelled_at' => $status === 'cancelled' ? $now->subDays(mt_rand(1, 20)) : null,
                'created_at' => $start->subDays(70),
                'updated_at' => $now,
            ]);

            $pct = mt_rand(60, 200); // this event is cheaper or dearer than the base prices (integer percent: no floats)
            $tiers = [];
            foreach (array_slice(self::TIERS, 0, mt_rand(3, 5)) as $order => [$name, $base]) {
                $price = intdiv(intdiv($base * $pct, 100) + 25, 50) * 50;
                $tierId = DB::table('ticket_types')->insertGetId([
                    'event_id' => $eventId,
                    'name' => $name,
                    'price_minor' => $price,
                    'quantity' => 1_000_000, // provisional: set from the real sales in recomputeStock()
                    'max_per_order' => 10,
                    'sort_order' => $order + 1,
                    'created_at' => $start->subDays(70),
                    'updated_at' => $now,
                ]);
                $tiers[] = ['id' => $tierId, 'price' => $price];
            }

            if ($status === 'published') {
                $sellable[] = ['id' => $eventId, 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'phase' => $phase, 'tiers' => $tiers];
            }
        }

        return $sellable;
    }

    /** @param array<int, array{id: int, start: int, end: int, phase: string, tiers: array<int, array{id: int, price: int}>}> $events */
    private function createOrders(array $events): void
    {
        $now = CarbonImmutable::now();
        $nowTs = $now->getTimestamp();
        $bps = (int) tenant('fee_percent_bps');
        $fixed = (int) tenant('fee_fixed_minor');
        $currency = (string) tenant('currency');

        // Some events are much more popular than others.
        $cumulative = [];
        $total = 0;
        foreach ($events as $event) {
            $total += mt_rand(1, 10);
            $cumulative[] = $total;
        }

        $orderId = (int) DB::table('orders')->max('id');
        $orderRows = [];
        $itemRows = [];
        $upcoming = array_values(array_filter($events, fn (array $e): bool => $e['phase'] === 'upcoming'));
        $activeHolds = min(25, $this->orders, $upcoming === [] ? 0 : 25); // a few orders still inside their 10 minute hold

        for ($n = 1; $n <= $this->orders; $n++) {
            $holding = $n <= $activeHolds;
            $event = $holding ? $upcoming[array_rand($upcoming)] : $events[$this->pick($cumulative, $total)];

            // When the buyer checked out: before the event, never in the future, spread over up to ~3 months.
            $latest = $holding ? $nowTs - 60 * mt_rand(1, 8) : min($event['start'], $nowTs - 900);
            $earliest = max($event['start'] - 90 * 86400, $nowTs - 120 * 86400);
            $createdTs = $holding ? $latest : mt_rand(min($earliest, $latest - 1), $latest);

            $lines = [];
            $priced = [];
            $tiers = $event['tiers'];
            shuffle($tiers);
            foreach (array_slice($tiers, 0, mt_rand(1, 2)) as $tier) {
                $quantity = mt_rand(1, 4);
                $lines[] = ['tier' => $tier, 'quantity' => $quantity];
                $priced[] = [$tier['price'], $quantity];
            }
            $money = FeeCalculator::totals($priced, $bps, $fixed);

            [$status, $stamps] = $this->outcome($holding, $createdTs, $nowTs);
            $orderId++;
            $created = CarbonImmutable::createFromTimestamp($createdTs);
            $orderRows[] = [
                'id' => $orderId,
                'reference' => 'ORD-'.Str::upper(Str::random(12)),
                'payment_reference' => 'pay_'.Str::lower(Str::random(12)),
                'event_id' => $event['id'],
                'buyer_name' => 'Buyer '.mt_rand(1, 5000),
                'buyer_email' => 'buyer'.mt_rand(1, 4000).'@example.test',
                'status' => $status,
                'currency' => $currency,
                'subtotal_minor' => $money->subtotalMinor,
                'fee_minor' => $money->feeMinor,
                'total_minor' => $money->totalMinor,
                'fee_percent_bps' => $bps,
                'fee_fixed_minor' => $fixed,
                'expires_at' => $created->addMinutes(10),
                'paid_at' => $stamps['paid_at'] ?? null,
                'failed_at' => $stamps['failed_at'] ?? null,
                'expired_at' => $stamps['expired_at'] ?? null,
                'refund_pending_at' => $stamps['refund_pending_at'] ?? null,
                'refunded_at' => $stamps['refunded_at'] ?? null,
                'created_at' => $created,
                'updated_at' => $stamps['updated_at'] ?? $created,
            ];

            foreach ($lines as $line) {
                $unitFee = FeeCalculator::ticketFee($line['tier']['price'], $bps, $fixed);
                $itemRows[] = [
                    'order_id' => $orderId,
                    'ticket_type_id' => $line['tier']['id'],
                    'ticket_type_name' => 'Tier '.$line['tier']['id'],
                    'unit_price_minor' => $line['tier']['price'],
                    'quantity' => $line['quantity'],
                    'line_total_minor' => $line['tier']['price'] * $line['quantity'],
                    'unit_fee_minor' => $unitFee,
                    'line_fee_minor' => $unitFee * $line['quantity'],
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }

            if (count($orderRows) >= self::CHUNK) {
                $this->flush($orderRows, $itemRows);
            }
        }
        $this->flush($orderRows, $itemRows);

        // Rows were inserted with explicit ids: move the sequence past them.
        DB::statement("SELECT setval(pg_get_serial_sequence('orders', 'id'), (SELECT COALESCE(MAX(id), 1) FROM orders))");
        // The line snapshot carries the real tier name.
        DB::statement('UPDATE order_items oi SET ticket_type_name = tt.name FROM ticket_types tt WHERE tt.id = oi.ticket_type_id');
    }

    /**
     * Status and the timestamps that go with it.
     *
     * @return array{0: string, 1: array<string, CarbonImmutable>}
     */
    private function outcome(bool $holding, int $createdTs, int $nowTs): array
    {
        $at = fn (int $ts): CarbonImmutable => CarbonImmutable::createFromTimestamp(min($ts, $nowTs));

        if ($holding) {
            return ['pending', []];
        }

        $roll = mt_rand(1, 1000);
        $paidTs = $createdTs + mt_rand(10, 300);
        $expiredTs = $createdTs + 600 + mt_rand(0, 60);

        return match (true) {
            $roll <= self::REFUNDED => ['refunded', ['paid_at' => $at($paidTs), 'refunded_at' => $at($paidTs + mt_rand(3600, 5 * 86400)), 'updated_at' => $at($paidTs + mt_rand(3600, 5 * 86400))]],
            $roll <= self::REFUNDED + self::EXPIRED => ['expired', ['expired_at' => $at($expiredTs), 'updated_at' => $at($expiredTs)]],
            $roll <= self::REFUNDED + self::EXPIRED + self::FAILED => ['failed', ['failed_at' => $at($createdTs + mt_rand(20, 120)), 'updated_at' => $at($createdTs + 120)]],
            $roll <= self::REFUNDED + self::EXPIRED + self::FAILED + self::REFUND_PENDING => ['refund_pending', ['expired_at' => $at($expiredTs), 'refund_pending_at' => $at($expiredTs + mt_rand(60, 1800)), 'updated_at' => $at($expiredTs + 1800)]],
            default => ['paid', ['paid_at' => $at($paidTs), 'updated_at' => $at($paidTs)]],
        };
    }

    /** @param array<int, int> $cumulative */
    private function pick(array $cumulative, int $total): int
    {
        $r = mt_rand(1, $total);
        foreach ($cumulative as $i => $edge) {
            if ($r <= $edge) {
                return $i;
            }
        }

        return count($cumulative) - 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $orderRows
     * @param  array<int, array<string, mixed>>  $itemRows
     */
    private function flush(array &$orderRows, array &$itemRows): void
    {
        if ($orderRows !== []) {
            DB::table('orders')->insert($orderRows);
            DB::table('order_items')->insert($itemRows);
        }
        $orderRows = [];
        $itemRows = [];
    }

    /** One ticket per admission of every paid order (refunded ones are void), straight from the order lines. */
    private function createTickets(): void
    {
        DB::statement(
            "INSERT INTO tickets (order_id, order_item_id, ticket_type_id, event_id, seq, code, status, created_at, updated_at)
             SELECT o.id, oi.id, oi.ticket_type_id, o.event_id, g.seq,
                    upper(substr(md5(random()::text || clock_timestamp()::text || oi.id::text || '-' || g.seq::text), 1, 20)),
                    CASE o.status WHEN 'refunded' THEN 'void' ELSE 'valid' END,
                    o.paid_at, COALESCE(o.refunded_at, o.paid_at)
               FROM orders o
               JOIN order_items oi ON oi.order_id = o.id
               CROSS JOIN LATERAL generate_series(1, oi.quantity) AS g(seq)
              WHERE o.status IN ('paid', 'refunded')",
        );
    }

    private function createRefunds(): void
    {
        $admin = User::query()->where('role', UserRole::Admin)->value('id');

        DB::statement(
            "INSERT INTO refunds (order_id, amount_minor, currency, reason, refunded_by, created_at, updated_at)
             SELECT id, total_minor, currency, 'Buyer request', ?, refunded_at, refunded_at FROM orders WHERE status = 'refunded'",
            [$admin],
        );
    }

    /** Stock counters come from the orders, not the other way round, so sold + held <= quantity always holds. */
    private function recomputeStock(): void
    {
        $now = CarbonImmutable::now()->toIso8601String();

        DB::statement(
            "UPDATE ticket_types tt SET
                sold = COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.ticket_type_id = tt.id AND o.status = 'paid'), 0),
                held = COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.ticket_type_id = tt.id AND o.status = 'pending' AND o.expires_at > ?::timestamptz), 0)",
            [$now],
        );
        // Capacity: a tenth of the tiers are sold out, the rest keep some room.
        DB::statement('UPDATE ticket_types SET quantity = GREATEST(1, sold + held + CASE WHEN random() < 0.1 THEN 0 ELSE 5 + floor(random() * 200)::int END)');
    }

    /** About 80 % of the valid tickets of events that are over walked in. */
    private function createCheckIns(): void
    {
        $door = User::query()->firstOrCreate(
            ['email' => 'door@demo.test'],
            ['name' => 'Door Staff', 'password' => Hash::make('password'), 'role' => UserRole::DoorStaff],
        );
        $now = CarbonImmutable::now()->toIso8601String();

        DB::statement(
            "UPDATE tickets t SET status = 'checked_in',
                    checked_in_at = LEAST(e.ends_at, e.starts_at + random() * interval '90 minutes'),
                    checked_in_by = ?,
                    gate_name = (ARRAY['Gate A', 'Gate B', 'Gate C'])[1 + floor(random() * 3)::int],
                    updated_at = e.ends_at
               FROM events e
              WHERE e.id = t.event_id AND e.ends_at < ?::timestamptz AND t.status = 'valid' AND random() < 0.8",
            [$door->id, $now],
        );
    }
}
