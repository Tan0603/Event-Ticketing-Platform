<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\RespondsWithTenantErrors;
use Closure;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

/**
 * Selects the tenant from the X-Tenant-ID header ONLY (no query-string fallback).
 * The header selects a tenant; it never grants access (tokens live in the tenant DB).
 */
class IdentifyTenantByHeader extends InitializeTenancyByRequestData
{
    use RespondsWithTenantErrors;

    public static $header = 'X-Tenant-ID';

    public static $queryParameter = null;

    public static $onFail = [self::class, 'onFail'];

    public function handle($request, Closure $next)
    {
        $id = $request->header(static::$header);

        if ($id === null || $id === '') {
            return static::headerMissing();
        }

        return parent::handle($request, $next);
    }
}
