<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Central\Tenant;
use Database\Seeders\Tenant\DemoSeeder;
use Illuminate\Console\Command;

/** Fills one (active) tenant with demo events, orders, tickets, refunds and check-ins. Safe to run twice. */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed {tenant : Tenant id} {--orders=60 : How many checkouts to simulate}';

    protected $description = 'Seed realistic demo data into a tenant (built through the real actions, so every invariant holds)';

    public function handle(): int
    {
        $tenant = Tenant::query()->find((string) $this->argument('tenant'));

        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            $this->error('Unknown or not yet active tenant.');

            return self::FAILURE;
        }

        $seeder = (new DemoSeeder(max(1, (int) $this->option('orders'))))->setCommand($this);
        $tenant->run(fn () => $seeder->run());

        return self::SUCCESS;
    }
}
