<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test data only: it writes the status directly, which production code never does (OrderStateMachine does).
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reference' => 'ORD-'.Str::upper(Str::random(12)),
            'event_id' => Event::factory(),
            'buyer_name' => fake()->name(),
            'buyer_email' => strtolower(fake()->unique()->safeEmail()),
            'status' => OrderStatus::Pending,
            'currency' => 'MYR',
            'subtotal_minor' => 10000,
            'fee_minor' => 550,
            'total_minor' => 10550,
            'fee_percent_bps' => 500,
            'fee_fixed_minor' => 50,
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function paid(): static
    {
        return $this->state(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    }
}
