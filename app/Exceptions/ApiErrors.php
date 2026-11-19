<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Every error is JSON with a stable `error` code. A client mistake is never a 500.
 */
final class ApiErrors
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => true);

        // A refused request (sold out, bad state, wrong credentials) is not a server problem: keep the log clean.
        $exceptions->dontReport([BusinessRuleException::class, InvalidCredentialsException::class]);

        $exceptions->render(fn (Throwable $e, Request $request): ?JsonResponse => self::render($e));
    }

    public static function render(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => response()->json([
                'error' => 'validation_failed',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status),
            $e instanceof BusinessRuleException => self::make($e->errorCode, $e->getMessage(), $e->status, [], $e->detail),
            $e instanceof AuthenticationException => self::make('unauthenticated', 'Authentication required.', 401),
            $e instanceof AuthorizationException => self::make('forbidden', 'This action is not allowed.', 403),
            // Route-model-binding misses and unknown routes arrive as NotFoundHttpException whose message names a model class.
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::make('not_found', 'Resource not found.', 404),
            $e instanceof InvalidSignatureException => self::make('invalid_signature', 'This link is invalid or has expired.', 403),
            $e instanceof HttpExceptionInterface => self::make(
                self::codeFor($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                $e->getStatusCode(),
                $e->getHeaders(),
            ),
            default => null, // fall through to Laravel (JSON, generic message when debug is off)
        };
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $detail
     */
    private static function make(string $error, string $message, int $status, array $headers = [], array $detail = []): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message] + $detail, $status, $headers);
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            419 => 'expired',
            422 => 'unprocessable',
            429 => 'too_many_requests',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };
    }
}
