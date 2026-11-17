<?php

namespace App\Http\Middleware;

use App\Actions\Auth\LoginStaff;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth for "the header selects, it never grants": even if a token row somehow existed
 * in another tenant's database, it is only honoured when it was issued for THIS tenant.
 * Must run after auth:sanctum.
 */
class EnsureTokenBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Exact match, NOT $token->can(): can() treats the '*' wildcard as "every ability",
        // which would let a wildcard token through for any tenant.
        $required = LoginStaff::tenantAbility((string) tenant('id'));

        if (! $token instanceof PersonalAccessToken || ! in_array($required, $token->abilities ?? [], true)) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'This token is not valid for this tenant.',
            ], 401);
        }

        return $next($request);
    }
}
