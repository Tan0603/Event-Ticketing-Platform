<?php

namespace Tests;

use App\Support\TenantDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static bool $sweptLeftoverDatabases = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Once per run: a crashed earlier run must not poison this one. Only the test prefix is swept,
        // so real tenant databases (tenant_*) are never touched.
        if (! self::$sweptLeftoverDatabases) {
            self::$sweptLeftoverDatabases = true;

            foreach (TenantDatabase::listWithPrefix((string) config('tenancy.database.prefix')) as $name) {
                TenantDatabase::dropNamed($name);
            }
        }
    }

    protected function tearDown(): void
    {
        // A request that initialized tenancy leaves it initialized in the test process.
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }

        // Drop every tenant database this test created.
        foreach (TenantDatabase::$created as $name) {
            TenantDatabase::dropNamed($name);
        }
        TenantDatabase::$created = [];

        parent::tearDown();
    }
}
