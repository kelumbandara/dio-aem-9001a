<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Services\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued every minute by the scheduler: when a queue worker runs it, the "worker" heartbeat is stamped (see {@see Heartbeat}). */
final class WorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->stamp('worker');
    }
}
