<?php

namespace App\Modules\Core\Http\Controllers\Api;

use App\Modules\Core\Services\Heartbeat;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Health endpoints expose status only, never versions, hosts or error text (the reason is reported to the log).
 *
 * live  = the process answers.
 * ready = the database answers; Redis answers when queue or cache use it; and, in production, the scheduler and the queue
 *         worker have shown signs of life in the last five minutes.
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(Heartbeat $heartbeat): JsonResponse
    {
        try {
            DB::select('select 1');
            if (config('queue.default') === 'redis' || config('cache.default') === 'redis') {
                Redis::connection()->ping();
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json(['status' => 'unavailable'], 503);
        }

        if (config('core.health.require_heartbeats')) {
            try {
                $dead = $heartbeat->dead();
            } catch (Throwable $e) {
                report($e);

                return response()->json(['status' => 'unavailable'], 503);
            }
            if ($dead !== []) {
                logger()->error('Health check: no sign of life from '.implode(' and ', $dead).' for over '.(Heartbeat::STALE_AFTER / 60).' minutes.');

                return response()->json(['status' => 'unavailable'], 503);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
