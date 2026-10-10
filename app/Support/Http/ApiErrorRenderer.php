<?php

namespace App\Support\Http;

use App\Support\Exceptions\BusinessException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders any exception in the standard error envelope (docs/architecture/api-standards.md §3).
 * Internals are never leaked: unexpected errors are always the generic message (they are still
 * reported to the log with the request id).
 */
final class ApiErrorRenderer
{
    public static function wants(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = self::describe($e);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => Context::get('request_id'),
            ],
        ], $status, $headers);
    }

    /**
     * @return array{int, string, string, list<array<string, string>>, array<string, string>}
     */
    private static function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof BusinessException => [$e->status, $e->errorCode, $e->getMessage(), $e->details, []],
            $e instanceof ValidationException => [422, 'validation.failed', __('The given data was invalid.'), self::validationDetails($e), []],
            $e instanceof AuthenticationException => [401, 'auth.unauthenticated', __('Unauthenticated.'), [], []],
            $e instanceof AuthorizationException => [403, 'auth.forbidden', __('This action is unauthorized.'), [], []],
            $e instanceof ModelNotFoundException => [404, 'resource.not_found', __('Resource not found.'), [], []],
            $e instanceof HttpExceptionInterface => self::http($e),
            default => [500, 'server.error', __('An unexpected error occurred.'), [], []],
        };
    }

    /**
     * @return array{int, string, string, list<array<string, string>>, array<string, string>}
     */
    private static function http(HttpExceptionInterface $e): array
    {
        $status = $e->getStatusCode();
        $headers = array_filter($e->getHeaders(), fn ($v, $k) => in_array(strtolower($k), ['retry-after', 'allow', 'x-ratelimit-limit', 'x-ratelimit-remaining'], true), ARRAY_FILTER_USE_BOTH);

        [$code, $message] = match ($status) {
            403 => ['auth.forbidden', __('This action is unauthorized.')],
            404 => ['resource.not_found', __('Resource not found.')],
            405 => ['http.method_not_allowed', __('Method not allowed.')],
            419 => ['http.page_expired', __('Page expired.')],
            429 => ['rate_limit.exceeded', __('Too many requests.')],
            default => ["http.{$status}", $status >= 500 ? __('An unexpected error occurred.') : ($e->getMessage() ?: __('Request failed.'))],
        };

        return [$status, $code, $message, [], $headers];
    }

    /** @return list<array{field: string, message: string}> */
    private static function validationDetails(ValidationException $e): array
    {
        $details = [];
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = ['field' => $field, 'message' => $message];
            }
        }

        return $details;
    }
}
