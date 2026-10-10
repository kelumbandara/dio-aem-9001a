<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Leaves a trace in the database so a test can see that a worker really processed it. */
class RedisProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $marker) {}

    public function handle(): void
    {
        DB::table('settings')->insert(['key' => 'redis.probe.'.$this->marker, 'scope' => 'global', 'scope_id' => 0, 'value' => json_encode('done'), 'created_at' => now(), 'updated_at' => now()]);
    }
}
