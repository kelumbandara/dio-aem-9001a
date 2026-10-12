<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Services\ExpoPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Hands a notification to the push service after the change that caused it has been saved. Retried if the service is down. */
final class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $key,
        public readonly array $params,
        public readonly ?string $url,
        public readonly string $level,
        public readonly ?string $subjectType = null,
        public readonly string|int|null $subjectId = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ExpoPushSender $sender): void
    {
        $sender->sendToUser($this->userId, $this->key, $this->params, $this->url, $this->level, $this->subjectType, $this->subjectId);
    }
}
