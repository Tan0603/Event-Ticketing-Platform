<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** One response for "unknown email" and "wrong password": no account enumeration. */
class InvalidCredentialsException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_credentials',
            'message' => 'The provided credentials are incorrect.',
        ], 401);
    }
}
