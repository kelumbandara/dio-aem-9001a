<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\MasterData\Approvals\PriceListPriceChange;
use App\Modules\MasterData\DTOs\ChangeOutcome;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Services\PriceChangeValidator;

/** Entry point for every price change (web, API, import, AI): applies now, or opens an approval request. */
final class RequestPriceChanges
{
    public function __construct(
        private readonly PriceChangeValidator $validator,
        private readonly ApprovalService $approvals,
        private readonly ApplyPriceChanges $apply,
    ) {}

    /** @param  list<array<string, mixed>>  $changes */
    public function __invoke(PriceList $list, array $changes, int $requestedBy, string $channel = 'web', ?string $idempotencyKey = null): ChangeOutcome
    {
        $clean = $this->validator->validate($list, $changes);

        $decision = $this->approvals->gate(
            PriceListPriceChange::KEY,
            ['price_list_id' => $list->id, 'price_list_code' => $list->code, 'changes' => $clean],
            $requestedBy,
            subject: $list,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
        );

        if ($decision->requiresApproval) {
            return ChangeOutcome::pending((string) $decision->requestId, (string) $decision->documentNo);
        }

        ($this->apply)($list->id, $clean);

        return ChangeOutcome::applied();
    }
}
