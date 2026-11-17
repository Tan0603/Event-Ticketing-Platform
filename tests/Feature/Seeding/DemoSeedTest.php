<?php

use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->tenant = provisionTenant('demo-org', 'admin@demo.test');
});

it('seeds a believable tenant that keeps every invariant', function () {
    $this->artisan('demo:seed', ['tenant' => 'demo-org', '--orders' => 30])->assertSuccessful();

    $this->tenant->run(function () {
        expect(Event::query()->count())->toBe(4)->and(Order::query()->count())->toBeGreaterThan(10);

        foreach (TicketType::query()->get() as $tier) {
            $live = Ticket::query()->where('ticket_type_id', $tier->id)->where('status', '!=', 'void')->count();

            expect($tier->sold + $tier->held)->toBeLessThanOrEqual($tier->quantity) // the CHECK constraint, seen from outside
                ->and($tier->sold)->toBe($live);                                      // sold counter = tickets that still count
        }

        // paid orders have tickets, nothing else does
        expect(DB::table('orders')->where('status', 'paid')->count())->toBeGreaterThan(0)
            ->and(DB::table('tickets')->join('orders', 'orders.id', '=', 'tickets.order_id')->whereIn('orders.status', ['pending', 'failed', 'expired'])->count())->toBe(0)
            ->and(DB::table('refunds')->count())->toBe(DB::table('orders')->where('status', 'refunded')->count())
            ->and(DB::table('tickets')->where('status', 'used')->count())->toBeGreaterThan(0)
            ->and(DB::table('users')->where('email', 'door@demo.test')->where('role', 'door_staff')->exists())->toBeTrue();
    });
});

it('is safe to run twice', function () {
    $this->artisan('demo:seed', ['tenant' => 'demo-org', '--orders' => 10])->assertSuccessful();
    $orders = $this->tenant->run(fn () => Order::query()->count());

    $this->artisan('demo:seed', ['tenant' => 'demo-org', '--orders' => 10])->assertSuccessful();

    expect($this->tenant->run(fn () => Order::query()->count()))->toBe($orders);
});

it('refuses an unknown tenant', function () {
    $this->artisan('demo:seed', ['tenant' => 'nobody'])->assertFailed();
});
