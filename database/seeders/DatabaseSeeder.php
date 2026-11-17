<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Central seeder. The central database only holds `tenants`, so there is nothing
 * to seed here; tenant data is seeded by database/seeders/Tenant/* (Phase 13).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
