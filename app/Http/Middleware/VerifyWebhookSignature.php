<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a payment webhook before anything reads its JSON.
 *
 * `X-Signature` must be the hex HMAC-SHA256 of the RAW request body, keyed with the webhook secret of the
 * tenant named in the URL (never one taken from the payload), compared in constant time.
 * Runs after tenancy is initialized from the path, so `tenant('webhook_secret')` is that tenant's.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = tenant('webhook_secret');
        $given = (string) $request->header('X-Signature', '');

        // Tolerate the common "sha256=<hex>" form.
        $given = str_starts_with($given, 'sha256=') ? substr($given, 7) : $given;

        if (! is_string($secret) || $secret === '' || $given === '') {
            return $this->reject();
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, strtolower($given))) {
            return $this->reject();
        }

        return $next($request);
    }

    private function reject(): JsonResponse
    {
        return response()->json(['error' => 'invalid_signature', 'message' => 'The webhook signature is missing or wrong.'], 401);
    }
}
