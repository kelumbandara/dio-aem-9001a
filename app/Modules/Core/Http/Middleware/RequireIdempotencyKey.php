<?php

namespace App\Modules\Core\Http\Middleware;

use App\Support\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency for document-creating POSTs (docs/architecture/api-standards.md §2).
 *
 *  - Header `Idempotency-Key` is required.
 *  - Same key + same request  -> the stored successful response is replayed.
 *  - Same key + different request -> 422 idempotency.key_reused.
 *  - Same key while the first request is still running -> 409 idempotency.in_progress.
 *  - Only 2xx responses are kept. A failed attempt releases the key so the client can retry.
 *
 * Place after the auth middleware so the key is scoped to the user.
 */
final class RequireIdempotencyKey
{
    private const REPLAYED_HEADERS = ['Content-Type', 'Location'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            throw new BusinessException('idempotency.key_required', 'A valid Idempotency-Key header (max 255 characters) is required.', 400);
        }

        $scope = 'user:'.($request->user()?->getAuthIdentifier() ?? 'guest');
        $hash = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());

        $claimed = DB::table('idempotency_keys')->insertOrIgnore([
            'scope' => $scope,
            'key' => $key,
            'method' => $request->method(),
            'path' => mb_substr($request->path(), 0, 255),
            'request_hash' => $hash,
            'expires_at' => now()->addHours((int) config('core.idempotency.ttl_hours', 24)),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        if (! $claimed) {
            return $this->replayOrReject($scope, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->release($scope, $key);
            throw $e;
        }

        if ($response->isSuccessful()) {
            DB::table('idempotency_keys')->where(['scope' => $scope, 'key' => $key])->update([
                'response_status' => $response->getStatusCode(),
                'response_headers' => json_encode(array_filter(
                    array_combine(self::REPLAYED_HEADERS, array_map(fn ($h) => $response->headers->get($h), self::REPLAYED_HEADERS)),
                )),
                'response_body' => $response->getContent(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $this->release($scope, $key);
        }

        return $response;
    }

    private function replayOrReject(string $scope, string $key, string $hash): Response
    {
        $row = DB::table('idempotency_keys')->where(['scope' => $scope, 'key' => $key])->first();

        if ($row === null) {
            // The competing request released the key between our insert and read; ask the client to retry.
            throw new BusinessException('idempotency.in_progress', 'A request with this Idempotency-Key is being processed.', 409);
        }
        if ($row->request_hash !== $hash) {
            throw new BusinessException('idempotency.key_reused', 'This Idempotency-Key was already used with a different request.', 422);
        }
        if ($row->response_status === null) {
            throw new BusinessException('idempotency.in_progress', 'A request with this Idempotency-Key is being processed.', 409);
        }

        $response = new LaravelResponse($row->response_body, $row->response_status);
        foreach (json_decode((string) $row->response_headers, true) ?: [] as $name => $value) {
            $response->headers->set($name, $value);
        }
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    private function release(string $scope, string $key): void
    {
        DB::table('idempotency_keys')->where(['scope' => $scope, 'key' => $key])->whereNull('response_status')->delete();
    }
}
