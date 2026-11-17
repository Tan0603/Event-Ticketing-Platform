<?php

namespace Tests;

use App\Support\TenantDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    private static bool $sweptLeftoverDatabases = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Expected failures (a test that provokes a 500) must not spray stack traces over the test output.
        config(['logging.default' => 'null']);

        // New tenant databases are cloned from a migrated template instead of running every migration again.
        config(['ticketing.tenant_template_database' => self::templateDatabase()]);

        // Once per run: a crashed earlier run must not poison this one. Only the test prefix is swept,
        // so real tenant databases (tenant_*) are never touched.
        if (! self::$sweptLeftoverDatabases) {
            self::$sweptLeftoverDatabases = true;

            foreach (TenantDatabase::listWithPrefix((string) config('tenancy.database.prefix')) as $name) {
                TenantDatabase::dropNamed($name);
            }
        }
    }

    private static ?string $template = null;

    /**
     * A tenant database with every tenant migration applied, built once and reused by every test (and every
     * later run, until a migration changes: the name carries a hash of the migration files).
     * It does not start with the test prefix, so the sweep above never drops it.
     */
    private static function templateDatabase(): string
    {
        if (self::$template !== null) {
            return self::$template;
        }

        $files = glob(database_path('migrations/tenant/*.php')) ?: [];
        sort($files);
        $name = 'tenanttpl_'.substr(md5(implode('|', array_map(fn (string $f): string => basename($f).md5_file($f), $files))), 0, 12);

        if (! TenantDatabase::existsNamed($name)) {
            foreach (TenantDatabase::listWithPrefix('tenanttpl_') as $stale) {
                TenantDatabase::dropNamed($stale);
            }

            $central = (string) config('tenancy.database.central_connection');
            DB::connection($central)->statement("CREATE DATABASE \"{$name}\" WITH TEMPLATE=template0");

            config(['database.connections.tenant' => array_merge((array) config("database.connections.{$central}"), ['database' => $name])]);
            DB::purge('tenant');
            Artisan::call('migrate', ['--database' => 'tenant'] + config('tenancy.migration_parameters'));
            DB::purge('tenant'); // nobody may stay connected to a database that is used as a template
        }

        return self::$template = $name;
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
