<?php

namespace App\Modules\Communications\Services\Channels;

use RuntimeException;

/** A sending that failed: `retryable` when trying again later may work (no connection, a busy service), not when the message itself was refused. */
final class ChannelException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
