<?php

namespace App\Modules\Inventory\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\StockMovement;

/**
 * Approval-gated action "post a stock adjustment" (write-offs and corrections change inventory value without a
 * business document behind them). With no policy for this key the adjustment is posted at once.
 */
final class StockAdjustmentPost implements Approvable
{
    public const KEY = 'inventory.adjustment.post';

    public function __construct(private readonly StockPoster $poster) {}

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
        return $payload['estimated_value'] ?? null;
    }

    public function summary(array $payload): string
    {
        $count = count($payload['movement']['lines'] ?? []);
        $reason = $payload['movement']['reason'] ?? '';

        return "Stock adjustment ({$reason}): {$count} line(s), about LKR ".($payload['estimated_value'] ?? '0');
    }

    public function revalidate(array $payload, int $requestedBy): void
    {
        // Exactly the checks and arithmetic of posting, undone afterwards: stock may have changed since the request.
        $this->poster->check($this->movement($payload, $requestedBy));
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        $posted = $this->poster->post($this->movement($payload, $token->requestedBy, (string) $token->requestId));

        return ['document_id' => $posted->documentId, 'document_no' => $posted->documentNo];
    }

    /** @param  array<string, mixed>  $payload */
    private function movement(array $payload, int $requestedBy, ?string $requestId = null): StockMovement
    {
        $m = StockMovement::fromArray($payload['movement']);

        // When executing, the approval request is the movement's source: the document links back to it, and it can only post once.
        return new StockMovement(
            $m->type, $m->postingDate, $m->reason, $m->lines, $m->reference, $m->notes,
            $requestId !== null ? 'approval_request' : null, $requestId, $requestedBy,
        );
    }
}
