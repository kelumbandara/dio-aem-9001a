<?php

namespace App\Modules\Approvals\Demo;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Core\Contracts\AuditLogger;

/**
 * DEVELOPMENT DEMO ONLY – registered in the `local` environment so the approval screens can be tried
 * before a real module (Procurement, Finance…) offers a gated action. Its "effect" is one audit entry.
 * It is never registered in testing or production.
 */
final class DemoExpense implements Approvable
{
    public const KEY = 'demo.expense.release';

    public function __construct(private readonly AuditLogger $audit) {}

    public static function approvalKey(): string
    {
        return self::KEY;
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
        return 'Demo expense: '.($payload['description'] ?? '').' (LKR '.($payload['amount'] ?? '?').')';
    }

    public function revalidate(array $payload, int $requestedBy): void {}

    public function execute(array $payload, ExecutionToken $token): array
    {
        $this->release($payload);

        return ['released' => true];
    }

    /**
     * What happens when no approval is needed (below the policy threshold).
     *
     * @param  array<string, mixed>  $payload
     */
    public function release(array $payload): void
    {
        $this->audit->record('demo.expense.released', null, [], [], ['description' => $payload['description'] ?? '', 'amount' => $payload['amount'] ?? null]);
    }
}
