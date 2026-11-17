<?php

namespace App\Http\Controllers\Central;

use App\Actions\Tenancy\RegisterTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\RegisterTenantRequest;
use App\Http\Resources\TenantRegistrationResource;
use App\Models\Central\Tenant;
use Illuminate\Http\JsonResponse;

/** Central routes: no X-Tenant-ID, tenancy is never initialized. */
class RegisterController extends Controller
{
    public function store(RegisterTenantRequest $request, RegisterTenant $register): JsonResponse
    {
        $tenant = $register->execute($request->toData());

        return (new TenantRegistrationResource($tenant))->response()->setStatusCode(202);
    }

    public function show(string $tenantId): TenantRegistrationResource
    {
        return new TenantRegistrationResource(Tenant::query()->findOrFail($tenantId));
    }
}
