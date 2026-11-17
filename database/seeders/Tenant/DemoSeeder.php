<?php

namespace Database\Seeders\Tenant;

use App\Actions\Checkout\CheckoutData;
use App\Actions\Checkout\PlaceOrder;
use App\Actions\Events\CancelEvent;
use App\Actions\Events\CreateEvent;
use App\Actions\Events\EventData;
use App\Actions\Events\PublishEvent;
use App\Actions\Orders\RefundOrder;
use App\Actions\Payments\ProcessPaymentWebhook;
use App\Actions\Tickets\CheckInTicket;
use App\Actions\TicketTypes\CreateTicketType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use App\Models\Tenant\TicketType;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Realistic demo data for ONE tenant, built through the same actions the API uses (CreateEvent, PlaceOrder,
 * ProcessPaymentWebhook, RefundOrder, CheckInTicket), so every invariant holds: sold + held never exceeds
 * quantity, tickets exist for paid orders, refunds return stock. Only the timestamps of orders are moved back
 * in time afterwards so the reports have a few days of history.
 *
 * Run inside tenant context: `php artisan demo:seed {tenant}`.
 */
class DemoSeeder extends Seeder
{
    public const SLUG = 'summer-music-fest';

    public function __construct(private readonly int $orders = 60) {}

    public function run(): void
    {
        if (Event::query()->where('slug', self::SLUG)->exists()) {
            $this->command->warn('Demo data is already there; nothing to do.');

            return;
        }

        mt_srand(2026); // mostly the same data every time (random picks of refunds/check-ins use the database)

        // All or nothing: a failure half way must not leave a half-seeded tenant that "already has demo data".
        DB::transaction(function (): void {
            $this->seed();
        });
    }

    private function seed(): void
    {

        $door = User::query()->firstOrCreate(
            ['email' => 'door@demo.test'],
            ['name' => 'Door Staff', 'password' => Hash::make('password'), 'role' => UserRole::DoorStaff],
        );

        $events = $this->events();
        $this->orders($events);
        $this->checkIns($door);
        $this->spreadOverDays();

        $this->command->info(sprintf(
            'Seeded %d events, %d orders, %d tickets. Door staff: door@demo.test / password',
            Event::query()->count(), Order::query()->count(), Ticket::query()->count(),
        ));
    }

    /** @return array<int, Event> the published events that take orders */
    private function events(): array
    {
        $create = app(CreateEvent::class);
        $publish = app(PublishEvent::class);
        $tier = app(CreateTicketType::class);
        $day = fn (int $days, int $hour = 19): CarbonImmutable => CarbonImmutable::now()->addDays($days)->setTime($hour, 0);

        $fest = $create->execute(new EventData('Summer Music Fest', self::SLUG, 'Three stages, thirty bands.', 'Bukit Jalil Arena', $day(30), $day(30, 23)));
        $tier->execute($fest, ['name' => 'Early Bird', 'price_minor' => 4500, 'quantity' => 100, 'max_per_order' => 6]);
        $tier->execute($fest, ['name' => 'General', 'price_minor' => 6500, 'quantity' => 300, 'max_per_order' => 8]);
        $tier->execute($fest, ['name' => 'VIP', 'price_minor' => 15000, 'quantity' => 40, 'max_per_order' => 4]);
        $fest = $publish->execute($fest);

        $meetup = $create->execute(new EventData('Laravel Meetup', 'laravel-meetup', 'Talks and pizza.', 'Co-working Space KL', $day(10, 18), $day(10, 21)));
        $tier->execute($meetup, ['name' => 'Free seat', 'price_minor' => 0, 'quantity' => 80, 'max_per_order' => 2]);
        $tier->execute($meetup, ['name' => 'Supporter', 'price_minor' => 2000, 'quantity' => 50, 'max_per_order' => 5]);
        $meetup = $publish->execute($meetup);

        $gala = $create->execute(new EventData('Winter Gala', 'winter-gala', 'Not on sale yet.', 'Grand Ballroom', $day(90), $day(90, 23)));
        $tier->execute($gala, ['name' => 'Table seat', 'price_minor' => 25000, 'quantity' => 120]);

        $expo = $create->execute(new EventData('Food Expo', 'food-expo', 'Cancelled by the organiser.', 'Convention Centre', $day(20, 10), $day(20, 18)));
        $tier->execute($expo, ['name' => 'Entry', 'price_minor' => 1500, 'quantity' => 200]);
        $expo = $publish->execute($expo);
        app(CancelEvent::class)->execute($expo);

        return [$fest, $meetup];
    }

    /** @param array<int, Event> $events */
    private function orders(array $events): void
    {
        $place = app(PlaceOrder::class);
        $webhook = app(ProcessPaymentWebhook::class);
        $tiers = TicketType::query()->whereIn('event_id', array_map(fn (Event $e): int => $e->id, $events))->get()->groupBy('event_id');

        for ($i = 1; $i <= $this->orders; $i++) {
            $event = $events[mt_rand(0, 9) < 7 ? 0 : 1]; // most buyers want the festival
            $tier = $tiers[$event->id]->random();
            $quantity = mt_rand(1, min(4, $tier->max_per_order));

            try {
                $order = $place->execute(new CheckoutData($event->slug, fake()->name(), "buyer{$i}@example.com", [$tier->id => $quantity], null))->order;
            } catch (BusinessRuleException) {
                continue; // sold out: fine, a demo does not need every order
            }

            if ($order->status !== OrderStatus::Pending) {
                continue; // free orders are paid at checkout
            }

            $outcome = mt_rand(1, 100);
            if ($outcome <= 12) {
                continue; // still pending: the buyer is thinking
            }

            $webhook->execute([
                'id' => 'evt_demo_'.Str::lower(Str::random(12)),
                'type' => $outcome <= 20 ? ProcessPaymentWebhook::FAILED : ProcessPaymentWebhook::SUCCEEDED,
                'data' => ['order_reference' => $order->reference, 'amount_minor' => $order->total_minor, 'currency' => $order->currency],
            ]);
        }

        // A few buyers asked for their money back.
        Order::query()->where('status', OrderStatus::Paid)->inRandomOrder()->limit(max(1, intdiv($this->orders, 12)))->pluck('id')
            ->each(fn (int $id) => app(RefundOrder::class)->execute($id, null, 'Demo: buyer changed plans'));
    }

    private function checkIns(User $door): void
    {
        $checkIn = app(CheckInTicket::class);

        // The meetup is soon: some of its buyers already walked in.
        Ticket::query()->whereHas('event', fn ($q) => $q->where('slug', 'laravel-meetup'))->where('status', 'valid')
            ->inRandomOrder()->limit(15)->pluck('code')
            ->each(fn (string $code) => $checkIn->execute($door, $code));
    }

    /** Orders happened over the last two weeks, not all in the last second. Status columns are never touched. */
    private function spreadOverDays(): void
    {
        $now = CarbonImmutable::now();

        foreach (Order::query()->orderBy('id')->get(['id', 'paid_at']) as $order) {
            $at = $now->subDays(mt_rand(0, 13))->subMinutes(mt_rand(0, 600));
            $update = ['created_at' => $at, 'updated_at' => $at, 'expires_at' => $at->addMinutes((int) config('ticketing.hold_minutes'))];

            if ($order->paid_at !== null) {
                $update['paid_at'] = $at->addMinutes(mt_rand(1, 8));
            }

            DB::table('orders')->where('id', $order->id)->update($update);
        }

        // A refund cannot predate its order's payment: put it on a later day (never in the future).
        DB::statement("UPDATE refunds r SET created_at = LEAST(o.paid_at + interval '1 day', now()), updated_at = LEAST(o.paid_at + interval '1 day', now()) FROM orders o WHERE o.id = r.order_id");
        DB::statement('UPDATE orders o SET refunded_at = r.created_at FROM refunds r WHERE r.order_id = o.id');
    }
}
