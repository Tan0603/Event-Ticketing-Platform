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

    /**
     * Every HTTP request starts with fresh auth guards, like a real request in a fresh PHP worker. Without
     * this the guard remembers the previous request's user and a test could pass or fail for the wrong reason
     * (e.g. tenant A's token silently "working" on tenant B).
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
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
