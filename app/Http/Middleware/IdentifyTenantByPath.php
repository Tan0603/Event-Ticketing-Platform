<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\RespondsWithTenantErrors;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

/**
 * For callers that cannot send headers (payment gateway, e-mailed links):
 * the tenant id is the {tenant} route segment.
 */
class IdentifyTenantByPath extends InitializeTenancyByPath
{
    use RespondsWithTenantErrors;

    public static $onFail = [self::class, 'onFail'];
}
