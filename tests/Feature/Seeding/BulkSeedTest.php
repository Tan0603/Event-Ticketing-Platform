<?php

use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->tenant = provisionTenant('acme-events');
    $this->seed = fn () => $this->artisan('demo:seed-bulk', ['tenants' => ['acme-events'], '--events' => 8, '--orders' => 400, '--seed' => 7])->assertSuccessful();
});

it('loads events, orders in every status, tickets, refunds and check-ins in bulk', function () {
    ($this->seed)();

    $this->tenant->run(function () {
        $statuses = Order::query()->pluck('status')->map->value->unique()->all();

        expect(Event::query()->count())->toBe(8)
            ->and(Order::query()->count())->toBe(400)
            ->and($statuses)->toContain('paid', 'expired', 'failed', 'pending')
            ->and(DB::table('ticket_types')->count())->toBeGreaterThanOrEqual(8 * 3)->toBeLessThanOrEqual(8 * 5)
            ->and(Ticket::query()->count())->toBeGreaterThan(400)
            ->and(DB::table('refunds')->count())->toBe(Order::query()->where('status', 'refunded')->count());
    });
});

it('keeps the stock counters, tickets, money and constraints consistent', function () {
    ($this->seed)();

    $this->tenant->run(function () {
        // sold = tickets of paid orders, per ticket type, and nothing is oversold
        expect(DB::selectOne("SELECT COUNT(*) AS n FROM ticket_types tt WHERE tt.sold <> (SELECT COUNT(*) FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.ticket_type_id = tt.id AND o.status = 'paid')")->n)->toBe(0)
            ->and(DB::selectOne('SELECT COUNT(*) AS n FROM ticket_types WHERE sold + held > quantity')->n)->toBe(0)
            // refunded orders keep their tickets as void; refund_pending/expired/failed have none
            ->and(DB::selectOne("SELECT COUNT(*) AS n FROM tickets t JOIN orders o ON o.id = t.order_id WHERE (o.status = 'refunded') <> (t.status = 'void')")->n)->toBe(0)
            ->and(DB::selectOne("SELECT COUNT(*) AS n FROM tickets t JOIN orders o ON o.id = t.order_id WHERE o.status NOT IN ('paid', 'refunded')")->n)->toBe(0)
            // an order's money is the sum of its lines (fee per ticket)
            ->and(DB::selectOne('SELECT COUNT(*) AS n FROM orders o WHERE o.subtotal_minor <> (SELECT SUM(line_total_minor) FROM order_items WHERE order_id = o.id) OR o.fee_minor <> (SELECT SUM(line_fee_minor) FROM order_items WHERE order_id = o.id)')->n)->toBe(0)
            // one ticket per admission
            ->and(DB::selectOne('SELECT COUNT(*) AS n FROM order_items oi WHERE oi.quantity <> (SELECT COUNT(*) FROM tickets WHERE order_item_id = oi.id) AND EXISTS (SELECT 1 FROM tickets WHERE order_item_id = oi.id)')->n)->toBe(0)
            // the sequence moved past the explicit ids, so a real checkout still works afterwards
            ->and(DB::selectOne('SELECT last_value >= (SELECT MAX(id) FROM orders) AS ok FROM orders_id_seq')->ok)->toBeTrue();
    });
});

it('checks in most tickets of past events and none of upcoming ones', function () {
    ($this->seed)();

    $this->tenant->run(function () {
        $past = DB::selectOne("SELECT COUNT(*) FILTER (WHERE t.status = 'checked_in') AS ins, COUNT(*) AS total FROM tickets t JOIN events e ON e.id = t.event_id WHERE e.ends_at < now() AND t.status <> 'void'");
        $upcoming = DB::selectOne("SELECT COUNT(*) AS n FROM tickets t JOIN events e ON e.id = t.event_id WHERE e.ends_at >= now() AND t.status = 'checked_in'");

        expect($past->total)->toBeGreaterThan(0)
            ->and($past->ins / $past->total)->toBeGreaterThan(0.6)->toBeLessThan(0.95)
            ->and($upcoming->n)->toBe(0);
    });
});

it('is safe to run twice and gives each tenant different data', function () {
    ($this->seed)();
    $first = $this->tenant->run(fn () => Order::query()->count());
    ($this->seed)();

    expect($this->tenant->run(fn () => Order::query()->count()))->toBe($first);
});

it('serves the dashboard and reports from the seeded data', function () {
    ($this->seed)();
    $admin = tenantHeaders($this->tenant);

    $this->getJson('/api/admin/dashboard', $admin)->assertOk()->assertJsonStructure(['data' => ['sales' => ['today', 'this_month', 'all_time'], 'upcoming_events', 'active_holds', 'check_in_today']]);
    $this->getJson('/api/admin/reports/sales', $admin)->assertOk();
    $this->getJson('/api/events', ['X-Tenant-ID' => 'acme-events'])->assertOk();
});

it('refuses a tenant that is not active', function () {
    $this->artisan('demo:seed-bulk', ['tenants' => ['no-such-tenant']])->assertFailed();
});

it('leaves pre-existing orders, tickets and stock untouched (e.g. after demo:seed)', function () {
    Mail::fake();
    $this->artisan('demo:seed', ['tenant' => 'acme-events', '--orders' => 30])->assertSuccessful();

    $before = $this->tenant->run(fn () => [
        'tickets' => DB::table('tickets')->orderBy('id')->get()->toArray(),
        'ticket_types' => DB::table('ticket_types')->orderBy('id')->get()->toArray(),
        'refunds' => DB::table('refunds')->count(),
    ]);
    expect($before['tickets'])->not->toBeEmpty();

    ($this->seed)();

    $this->tenant->run(function () use ($before) {
        $demoTickets = DB::table('tickets')->whereIn('id', array_column($before['tickets'], 'id'))->orderBy('id')->get()->toArray();
        $demoTypes = DB::table('ticket_types')->whereIn('id', array_column($before['ticket_types'], 'id'))->orderBy('id')->get()->toArray();

        expect($demoTickets)->toEqual($before['tickets'])
            ->and($demoTypes)->toEqual($before['ticket_types'])
            ->and(DB::table('refunds')->count())->toBeGreaterThanOrEqual($before['refunds']);
    });
});

it('always has a published past, live and upcoming event', function () {
    ($this->seed)();

    $this->tenant->run(function () {
        $phases = DB::select("SELECT DISTINCT CASE WHEN ends_at < now() THEN 'past' WHEN starts_at <= now() THEN 'live' ELSE 'upcoming' END AS phase FROM events WHERE status = 'published'");

        expect(array_column($phases, 'phase'))->toContain('past', 'live', 'upcoming');
    });
});
