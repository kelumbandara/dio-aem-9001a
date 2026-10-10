<?php

namespace App\Modules\Approvals\Jobs;

use App\Modules\Approvals\Actions\ExecuteApprovedRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Runs an approved request. Idempotent: the action itself guards against double execution. */
class ExecuteApprovedRequestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $requestId) {}

    public function handle(ExecuteApprovedRequest $execute): void
    {
        $execute($this->requestId);
    }
}
