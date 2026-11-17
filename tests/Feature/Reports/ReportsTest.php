<?php

use App\Actions\Orders\RefundOrder;
use App\Enums\UserRole;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * Timeline (tenant timezone Asia/Kuala_Lumpur, UTC+8):
 *   Jan 4 10:00 UTC (18:00 KL)  order1 = 2 x General  -> 10550   paid
 *   Jan 4 10:00 UTC (18:00 KL)  order2 = 1 x VIP      -> 12650   paid
 *   Jan 4 17:00 UTC (01:00 KL on Jan 5!)  order3 = 1 x General -> 5300   paid
 *   Jan 6 03:00 UTC (11:00 KL)  order1 refunded (10550)
 *   a pending order (1 x General) still holds a ticket
 */
beforeEach(function () {
    Mail::fake();
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->pub = ['X-Tenant-ID' => 'acme-events'];

    [$this->event, $this->general, $this->vip] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'title' => 'Sunset Fest', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);

        return [
            $event,
            TicketType::factory()->for($event)->create(['name' => 'General', 'price_minor' => 5000, 'quantity' => 50, 'max_per_order' => 10, 'sort_order' => 1]),
            TicketType::factory()->for($event)->create(['name' => 'VIP', 'price_minor' => 12000, 'quantity' => 10, 'max_per_order' => 5, 'sort_order' => 2]),
        ];
    });

    $this->checkout = fn (int $typeId, int $quantity, string $email = 'buyer@example.com') => $this->postJson('/api/events/sunset/checkout', [
        'buyer_name' => 'Aisha', 'buyer_email' => $email, 'items' => [['ticket_type_id' => $typeId, 'quantity' => $quantity]],
    ], $this->pub)->assertCreated();

    $this->sell = function (int $typeId, int $quantity, string $at, string $email = 'buyer@example.com') {
        Carbon::setTestNow($at);
        $order = ($this->checkout)($typeId, $quantity, $email);
        $body = json_encode(['id' => 'evt_'.uniqid(), 'type' => 'payment.succeeded', 'data' => ['order_reference' => $order->json('data.reference'), 'amount_minor' => $order->json('data.total_minor'), 'currency' => 'MYR']]);
        $this->call('POST', '/api/webhooks/acme-events/payments', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
        ], $body)->assertOk();

        return $order->json('data.reference');
    };

    // Builds the timeline above and returns an admin token valid at "now" (the clock ends on Jan 6).
    $this->timeline = function () {
        $order1 = ($this->sell)($this->general->id, 2, '2027-01-04 10:00:00', 'one@example.com');
        ($this->sell)($this->vip->id, 1, '2027-01-04 10:00:00', 'two@example.com');
        ($this->sell)($this->general->id, 1, '2027-01-04 17:00:00', 'three@example.com');

        Carbon::setTestNow('2027-01-06 03:00:00');
        $this->tenant->run(fn () => app(RefundOrder::class)->execute(Order::query()->where('reference', $order1)->value('id'), null, 'test'));
        ($this->checkout)($this->general->id, 1, 'pending@example.com');

        $this->admin = tenantHeaders($this->tenant);
    };
});

describe('sales by day', function () {
    it('counts a sale on the day it was paid and a refund on the day it happened, in the tenant timezone', function () {
        ($this->timeline)();

        $report = $this->getJson('/api/admin/reports/sales?from=2027-01-03&to=2027-01-07', $this->admin)->assertOk()->json('data');
        $days = collect($report['days'])->keyBy('date');

        expect($report['days'])->toHaveCount(5)
            ->and($days['2027-01-03'])->toMatchArray(['orders_paid' => 0, 'gross_minor' => 0, 'refunded_minor' => 0, 'net_minor' => 0])
            ->and($days['2027-01-04'])->toMatchArray(['orders_paid' => 2, 'gross_minor' => 23200, 'fees_minor' => 1200, 'refunds' => 0, 'refunded_minor' => 0, 'net_minor' => 23200])
            // 17:00 UTC is already the next day in Kuala Lumpur
            ->and($days['2027-01-05'])->toMatchArray(['orders_paid' => 1, 'gross_minor' => 5300, 'fees_minor' => 300])
            // the refunded order stays a sale on Jan 4 and shows up as a refund on Jan 6
            ->and($days['2027-01-06'])->toMatchArray(['orders_paid' => 0, 'gross_minor' => 0, 'refunds' => 1, 'refunded_minor' => 10550, 'net_minor' => -10550])
            ->and($days['2027-01-07']['orders_paid'])->toBe(0)
            ->and($report['totals'])->toBe(['orders_paid' => 3, 'gross_minor' => 28500, 'fees_minor' => 1500, 'refunds' => 1, 'refunded_minor' => 10550, 'net_minor' => 17950]);
    });

    it('leaves out pending orders and days outside the range', function () {
        ($this->timeline)();

        $report = $this->getJson('/api/admin/reports/sales?from=2027-01-05&to=2027-01-05', $this->admin)->assertOk()->json('data');

        expect($report['days'])->toHaveCount(1)->and($report['totals']['orders_paid'])->toBe(1)->and($report['totals']['gross_minor'])->toBe(5300);
    });

    it('defaults to the last 30 days ending today', function () {
        ($this->timeline)();

        $report = $this->getJson('/api/admin/reports/sales', $this->admin)->assertOk()->json('data');

        expect($report['days'])->toHaveCount(30)->and($report['to'])->toBe('2027-01-06')->and($report['from'])->toBe('2026-12-08');
    });

    it('validates the range', function () {
        $admin = tenantHeaders($this->tenant);

        $this->getJson('/api/admin/reports/sales?from=2027-02-01&to=2027-01-01', $admin)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->getJson('/api/admin/reports/sales?from=01-02-2027', $admin)->assertStatus(422);
        $this->getJson('/api/admin/reports/sales?from=2020-01-01&to=2027-01-01', $admin)->assertStatus(422);
    });
});

describe('dashboard', function () {
    it('summarises the tenant', function () {
        ($this->timeline)();

        $data = $this->getJson('/api/admin/dashboard', $this->admin)->assertOk()->json('data');

        expect($data['currency'])->toBe('MYR')
            ->and($data['orders_by_status'])->toBe(['pending' => 1, 'paid' => 2, 'failed' => 0, 'expired' => 0, 'refunded' => 1])
            ->and($data['sales'])->toBe(['orders_paid' => 3, 'gross_minor' => 28500, 'fees_minor' => 1500, 'refunds' => 1, 'refunded_minor' => 10550, 'net_minor' => 17950])
            ->and($data['tickets'])->toBe(['sold' => 2, 'held' => 1, 'capacity' => 60, 'checked_in' => 0, 'awaiting_check_in' => 2])
            ->and($data['upcoming_events'])->toBe(1)
            ->and($data['top_events'])->toBe([['id' => $this->event->id, 'title' => 'Sunset Fest', 'orders' => 2, 'net_minor' => 17950]]);
    });

    it('is empty, not an error, for a tenant with no orders', function () {
        $data = $this->getJson('/api/admin/dashboard', tenantHeaders($this->tenant))->assertOk()->json('data');

        expect($data['sales']['gross_minor'])->toBe(0)->and($data['top_events'])->toBe([])->and($data['orders_by_status']['paid'])->toBe(0);
    });

    it('is refreshed as soon as an order is paid, despite the cache', function () {
        ($this->sell)($this->general->id, 1, '2027-01-04 10:00:00');
        $admin = tenantHeaders($this->tenant);
        expect($this->getJson('/api/admin/dashboard', $admin)->json('data.sales.orders_paid'))->toBe(1);

        ($this->sell)($this->general->id, 1, '2027-01-04 10:05:00', 'again@example.com');

        expect($this->getJson('/api/admin/dashboard', $admin)->json('data.sales.orders_paid'))->toBe(2);
    });

    it('uses the same number of queries however many orders there are', function () {
        $queries = function (string $url): int {
            $count = 0;
            DB::listen(function () use (&$count) {
                $count++;
            });
            $this->getJson($url, tenantHeaders($this->tenant))->assertOk();

            return $count;
        };

        ($this->sell)($this->general->id, 1, '2027-01-04 10:00:00');
        $few = [$queries('/api/admin/dashboard'), $queries("/api/admin/events/{$this->event->id}/report"), $queries('/api/admin/reports/sales?from=2027-01-01&to=2027-01-10')];

        foreach (range(1, 6) as $i) {
            ($this->sell)($this->general->id, 1, '2027-01-04 10:00:00', "buyer{$i}@example.com");
        }
        $many = [$queries('/api/admin/dashboard'), $queries("/api/admin/events/{$this->event->id}/report"), $queries('/api/admin/reports/sales?from=2027-01-01&to=2027-01-10')];

        expect($many)->toBe($few);
    });
});

describe('event report', function () {
    it('breaks sales and attendance down per ticket type', function () {
        ($this->timeline)();
        $code = $this->tenant->run(fn () => DB::table('tickets')->where('ticket_type_id', $this->vip->id)->value('code'));
        $this->postJson('/api/check-in', ['code' => $code], tenantHeaders($this->tenant, UserRole::DoorStaff))->assertOk();

        $data = $this->getJson("/api/admin/events/{$this->event->id}/report", $this->admin)->assertOk()->json('data');
        $types = collect($data['ticket_types'])->keyBy('name');

        expect($data['event'])->toBe(['id' => $this->event->id, 'title' => 'Sunset Fest', 'status' => 'published'])
            ->and($types['General'])->toMatchArray(['capacity' => 50, 'sold' => 1, 'held' => 1, 'available' => 48, 'gross_minor' => 15000, 'refunded_minor' => 10000, 'net_minor' => 5000, 'checked_in' => 0, 'awaiting_check_in' => 1, 'void' => 2])
            ->and($types['VIP'])->toMatchArray(['capacity' => 10, 'sold' => 1, 'gross_minor' => 12000, 'refunded_minor' => 0, 'net_minor' => 12000, 'checked_in' => 1, 'awaiting_check_in' => 0, 'void' => 0])
            ->and($data['totals'])->toMatchArray(['capacity' => 60, 'sold' => 2, 'held' => 1, 'gross_minor' => 27000, 'refunded_minor' => 10000, 'net_minor' => 17000, 'fees_minor' => 950, 'checked_in' => 1, 'awaiting_check_in' => 1, 'void' => 2])
            ->and($data['orders_by_status'])->toBe(['pending' => 1, 'paid' => 2, 'failed' => 0, 'expired' => 0, 'refunded' => 1]);
    });

    it('answers 404 for an unknown event', function () {
        $this->getJson('/api/admin/events/999999/report', tenantHeaders($this->tenant))->assertNotFound();
    });
});

describe('access and isolation', function () {
    it('is for admins only', function () {
        $door = tenantHeaders($this->tenant, UserRole::DoorStaff);

        foreach (['/api/admin/dashboard', '/api/admin/reports/sales', "/api/admin/events/{$this->event->id}/report"] as $url) {
            $this->getJson($url, $door)->assertForbidden();
            $this->getJson($url, $this->pub)->assertUnauthorized();
        }
    });

    it('never mixes tenants', function () {
        ($this->timeline)();
        $other = provisionTenant('other-org', 'admin@other.test');
        $otherAdmin = tenantHeaders($other);

        $this->getJson('/api/admin/dashboard', $otherAdmin)->assertOk()
            ->assertJsonPath('data.sales.gross_minor', 0)->assertJsonPath('data.orders_by_status.paid', 0)->assertJsonPath('data.top_events', []);
        $this->getJson('/api/admin/reports/sales?from=2027-01-03&to=2027-01-07', $otherAdmin)->assertJsonPath('data.totals.gross_minor', 0);
        $this->getJson("/api/admin/events/{$this->event->id}/report", $otherAdmin)->assertNotFound();
        // and the cached numbers of A are still A's
        $this->getJson('/api/admin/dashboard', $this->admin)->assertJsonPath('data.sales.gross_minor', 28500);
    });
});
