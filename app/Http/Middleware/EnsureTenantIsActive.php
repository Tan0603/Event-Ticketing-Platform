<?php

namespace App\Http\Middleware;

use App\Models\Central\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fail closed: a tenant that is provisioning or failed serves nothing (409). */
class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tenant|null $tenant */
        $tenant = tenant();

        if ($tenant === null || ! $tenant->status->isReady()) {
            tenancy()->end();

            return response()->json([
                'error' => 'tenant_not_ready',
                'status' => $tenant?->status->value,
                'message' => 'This tenant is not ready yet.',
            ], 409);
        }

        return $next($request);
    }
}
