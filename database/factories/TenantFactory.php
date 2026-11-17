<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => 'org-'.Str::lower(Str::random(8)),
            'name' => fake()->company(),
            'status' => TenantStatus::Active,
            'currency' => 'MYR',
            'timezone' => 'Asia/Kuala_Lumpur',
            'fee_percent_bps' => config('ticketing.default_fee_percent_bps'),
            'fee_fixed_minor' => config('ticketing.default_fee_fixed_minor'),
        ];
    }

    public function provisioning(): static
    {
        return $this->state(['status' => TenantStatus::Provisioning]);
    }

    public function failed(): static
    {
        return $this->state(['status' => TenantStatus::Failed, 'failure_reason' => 'test failure']);
    }
}
