<?php

namespace App\Modules\Communications\Jobs;

use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Services\MessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Sends one queued message after the change that queued it has been saved; tries again later when the channel was busy. */
final class DeliverMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $messageId)
    {
        $this->afterCommit();
    }

    public function failed(Throwable $e): void
    {
        // the queue gave up (the channel stayed down): the message is marked failed so a person can see it and try again
        Message::query()->whereKey($this->messageId)->whereIn('status', ['queued', 'sending'])
            ->update(['status' => 'failed', 'error_code' => 'comm.gave_up', 'error' => 'The message could not be delivered after several tries.', 'failed_at' => now()]);
    }

    public function handle(MessageService $messages): void
    {
        if ($messages->deliver($this->messageId)) {
            $this->release(60 * max(1, $this->attempts()));
        }
    }
}
