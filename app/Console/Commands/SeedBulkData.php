<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Central\Tenant;
use Database\Seeders\Tenant\BulkSeeder;
use Illuminate\Console\Command;

/**
 * Loads a realistic amount of data (spec section 18) into one or more active tenants, in bulk, to measure
 * query efficiency, indexes, reports and caches. Safe to run twice (a tenant that already has it is skipped).
 */
class SeedBulkData extends Command
{
    protected $signature = 'demo:seed-bulk
        {tenants* : One or more tenant ids}
        {--events=40 : Events per tenant (30-50 for the spec)}
        {--orders=30000 : Orders per tenant (20 000-50 000 for the spec)}
        {--seed=2026 : Random seed (combined with the tenant id: the same data every run, different data per tenant)}';

    protected $description = 'Bulk-seed events, orders, tickets, refunds and check-ins for load and report testing';

    public function handle(): int
    {
        $failed = false;

        foreach ((array) $this->argument('tenants') as $id) {
            $tenant = Tenant::query()->find((string) $id);

            if ($tenant === null || $tenant->status !== TenantStatus::Active) {
                $this->error("{$id}: unknown or not yet active tenant.");
                $failed = true;

                continue;
            }

            $this->line("<comment>{$id}</comment>");
            $seeder = (new BulkSeeder(max(1, (int) $this->option('events')), max(1, (int) $this->option('orders')), (int) $this->option('seed') + crc32((string) $id) % 100000))->setCommand($this);
            $tenant->run(fn () => $seeder->run());
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
