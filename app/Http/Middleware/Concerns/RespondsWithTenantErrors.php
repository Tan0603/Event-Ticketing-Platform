<?php

namespace App\Http\Middleware\Concerns;

use Illuminate\Http\JsonResponse;

trait RespondsWithTenantErrors
{
    protected static function headerMissing(): JsonResponse
    {
        return response()->json([
            'error' => 'tenant_header_missing',
            'message' => 'The X-Tenant-ID header is required.',
        ], 400);
    }

    public static function onFail(): JsonResponse
    {
        return response()->json([
            'error' => 'tenant_not_found',
            'message' => 'Unknown tenant.',
        ], 404);
    }
}
