<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use Illuminate\Http\JsonResponse;

/** What an admin needs to wire up the payment provider. Route group middleware `can:access-admin` keeps door staff out. */
class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return response()->json(['data' => [
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone,
            'fee_percent_bps' => $tenant->fee_percent_bps,
            'fee_fixed_minor' => $tenant->fee_fixed_minor,
            'payments' => [
                // Give the provider this URL and secret: every call is signed (HMAC-SHA256 of the raw body, header X-Signature).
                'webhook_url' => url("/api/webhooks/payments/{$tenant->id}"),
                'webhook_secret' => $tenant->webhook_secret,
            ],
        ]]);
    }
}
