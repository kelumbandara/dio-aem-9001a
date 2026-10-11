<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Inventory\Approvals\StockAdjustmentPost;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\PostOutcome;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\Inventory\Models\StockDocument;

/**
 * Entry point for people (web, API, imports): receipts, issues and transfers post at once; adjustments go through
 * the approval gate and post at once only when no policy asks for approval. Other modules call StockPoster directly
 * for their own documents.
 */
final class PostStockMovement
{
    public function __construct(
        private readonly StockPoster $poster,
        private readonly ApprovalService $approvals,
    ) {}

    public function __invoke(StockMovement $movement, int $by, string $channel = 'web', ?string $idempotencyKey = null): PostOutcome
    {
        $movement = new StockMovement(
            $movement->type, $movement->postingDate, $movement->reason, $movement->lines, $movement->reference, $movement->notes,
            $movement->sourceType, $movement->sourceId, $by,
        );

        if ($movement->type !== StockDocument::ADJUSTMENT) {
            return PostOutcome::posted($this->poster->post($movement));
        }

        // Check first: the requester hears about a bad adjustment now, and approvers see a real value.
        $preview = $this->poster->check($movement);
        $decision = $this->approvals->gate(
            StockAdjustmentPost::KEY,
            ['movement' => $movement->toArray(), 'estimated_value' => $preview->totalValue],
            $by,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
        );

        if ($decision->requiresApproval) {
            return PostOutcome::pending((string) $decision->requestId, (string) $decision->documentNo);
        }

        return PostOutcome::posted($this->poster->post($movement));
    }
}
