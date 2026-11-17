<?php

namespace Database\Factories;

use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketType> */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => ucfirst(fake()->unique()->word()).' pass',
            'description' => fake()->sentence(),
            'price_minor' => 5000,
            'quantity' => 100,
            'max_per_order' => 10,
            'sort_order' => 0,
        ];
    }
}
