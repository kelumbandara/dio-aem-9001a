<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\RedisProbeJob;

/**
 * Talks to the real Redis (docker compose up -d redis, or the CI service). Skipped when Redis is not
 * reachable so the suite still runs on a machine without it.
 */
function redisAvailable(): bool
{
    try {
        config(['database.redis.default.timeout' => 1]);
        Redis::connection()->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('pushes a job through Redis and a worker processes it', function () {
    config(['queue.default' => 'redis', 'queue.connections.redis.queue' => $queue = 'dio-test-'.uniqid()]);
    $marker = uniqid();

    RedisProbeJob::dispatch($marker);
    expect(Redis::connection()->llen("queues:{$queue}"))->toBe(1);

    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1]);

    expect(Redis::connection()->llen("queues:{$queue}"))->toBe(0)
        ->and(DB::table('settings')->where('key', 'redis.probe.'.$marker)->exists())->toBeTrue();
})->skip(fn () => ! redisAvailable(), 'Redis is not reachable');

it('uses Redis for the cache and rate limiter store when configured', function () {
    config(['cache.default' => 'redis']);
    Cache::put('dio-test-key', 'value', 30);

    expect(Cache::get('dio-test-key'))->toBe('value');
    Cache::forget('dio-test-key');
})->skip(fn () => ! redisAvailable(), 'Redis is not reachable');
