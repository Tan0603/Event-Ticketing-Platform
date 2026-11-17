<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Tenant\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);
        $start = now()->addDays(30)->startOfHour();

        return [
            'title' => ucfirst($title),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->sentence(),
            'venue_name' => fake()->company().' Hall',
            'venue_address' => fake()->streetAddress().', Kuala Lumpur',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(4),
            'status' => EventStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => EventStatus::Published, 'published_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => EventStatus::Cancelled, 'cancelled_at' => now()]);
    }
}
