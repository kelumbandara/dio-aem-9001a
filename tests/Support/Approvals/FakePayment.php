<?php

namespace Tests\Support\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Core\Contracts\EventOutbox;
use App\Support\Exceptions\BusinessException;
use RuntimeException;

/**
 * A stand-in gated action for tests. Its effect is a row in the outbox so that it is visible from
 * other processes and rolls back with the surrounding transaction like real domain writes would.
 */
class FakePayment implements Approvable
{
    /** Request ids executed in this process. */
    public static array $executed = [];

    /** Make revalidate() fail, like a closed period would. */
    public static bool $blocked = false;

    public static function reset(): void
    {
        self::$executed = [];
        self::$blocked = false;
    }

    public static function approvalKey(): string
    {
        return 'test.payment.release';
    }

    public function riskLevel(): string
    {
        return 'R3';
    }

    public function amount(array $payload): ?string
    {
        return $payload['amount'] ?? null;
    }

    public function summary(array $payload): string
    {
        return 'Release payment '.($payload['amount'] ?? '?').' to '.($payload['payee'] ?? '?');
    }

    public function revalidate(array $payload, int $requestedBy): void
    {
        if (self::$blocked || ! empty($payload['fail_revalidate'])) {
            throw new BusinessException('test.period_closed', 'The accounting period is closed.', 423);
        }
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        app(EventOutbox::class)->record('test.payment_released', ['request_id' => $token->requestId, 'amount' => $payload['amount'] ?? null]);

        if (! empty($payload['explode'])) {
            throw new RuntimeException('secret internal detail: db password is hunter2');
        }

        self::$executed[] = $token->requestId;

        return ['released' => true, 'by_hash' => substr($token->payloadHash, 0, 8)];
    }
}
