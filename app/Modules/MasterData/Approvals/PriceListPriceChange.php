<?php

namespace App\Modules\MasterData\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\MasterData\Actions\ApplyPriceChanges;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Services\PriceChangeValidator;

/** Approval-gated action "change prices on a price list". Gated only when a policy exists for the key. */
final class PriceListPriceChange implements Approvable
{
    public const KEY = 'masterdata.price_list.prices.change';

    public function __construct(
        private readonly ApplyPriceChanges $apply,
        private readonly PriceChangeValidator $validator,
    ) {}

    public static function approvalKey(): string
    {
        return self::KEY;
    }

    public function riskLevel(): string
    {
        return 'R3'; // price changes commit money terms (docs/architecture/ai-agents.md §3)
    }

    public function amount(array $payload): ?string
    {
        return null;
    }

    public function summary(array $payload): string
    {
        $n = count($payload['changes'] ?? []);

        return "Change {$n} price(s) on price list {$payload['price_list_code']}";
    }

    public function revalidate(array $payload, int $requestedBy): void
    {
        $list = PriceList::query()->find($payload['price_list_id'] ?? 0)
            ?? throw MasterDataException::notFound('price_list');

        $this->validator->validate($list, $payload['changes'] ?? []);
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        return ($this->apply)((int) $payload['price_list_id'], $payload['changes']);
    }
}
