<?php

namespace App\Support;

use App\Models\Central\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Idempotent create/drop of a tenant's PostgreSQL database. Every method is "ensure", never "create",
 * so a retried provisioning run can never fail on "database already exists" or "does not exist".
 *
 * Runs on the central connection, outside any transaction (CREATE DATABASE cannot run in one).
 */
final class TenantDatabase
{
    /** @var array<int, string> databases created by this process (used by test cleanup) */
    public static array $created = [];

    public static function name(Tenant $tenant): string
    {
        return $tenant->database()->getName();
    }

    public static function exists(Tenant $tenant): bool
    {
        return self::existsNamed(self::name($tenant));
    }

    public static function ensureExists(Tenant $tenant): void
    {
        $name = self::guard(self::name($tenant));

        if (self::existsNamed($name)) {
            return;
        }

        // Optionally clone an already migrated database (a few ms) instead of migrating from scratch (hundreds of ms).
        $template = (string) config('ticketing.tenant_template_database');
        $source = $template !== '' && self::existsNamed($template) ? self::guard($template) : 'template0';

        try {
            self::central()->statement("CREATE DATABASE \"{$name}\" WITH TEMPLATE=\"{$source}\"");
            self::$created[] = $name;
        } catch (QueryException $e) {
            // 42P04 duplicate_database / 23505 unique_violation on pg_database: a concurrent run won the race.
            if (! in_array($e->errorInfo[0] ?? null, ['42P04', '23505'], true)) {
                throw $e;
            }
        }
    }

    public static function dropIfExists(Tenant $tenant): void
    {
        self::dropNamed(self::name($tenant));
    }

    public static function dropNamed(string $name): void
    {
        $name = self::guard($name);

        // Close our own connection to it first, otherwise DROP waits on ourselves.
        DB::purge('tenant');

        self::central()->statement("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)");
    }

    /** @return array<int, string> names of existing databases starting with the given prefix */
    public static function listWithPrefix(string $prefix): array
    {
        return self::central()
            ->table('pg_database')
            ->where('datname', 'like', str_replace('_', '\_', $prefix).'%')
            ->pluck('datname')
            ->all();
    }

    public static function existsNamed(string $name): bool
    {
        return self::central()->table('pg_database')->where('datname', $name)->exists();
    }

    private static function central(): Connection
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    /** Names are interpolated into DDL, so only ever allow the shape we generate. */
    private static function guard(string $name): string
    {
        if (! preg_match('/^[a-z0-9_]{1,63}$/', $name)) {
            throw new InvalidArgumentException("Refusing unsafe database name [{$name}].");
        }

        return $name;
    }
}
