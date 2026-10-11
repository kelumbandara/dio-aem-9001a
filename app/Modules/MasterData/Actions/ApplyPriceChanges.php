<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\PriceListItem;
use App\Modules\MasterData\Services\PriceChangeValidator;
use Illuminate\Support\Facades\DB;

/**
 * Writes validated price changes. Called directly when no approval policy applies, and by the approval
 * engine (through PriceListPriceChange) after approval. Never call it with unvalidated input.
 */
final class ApplyPriceChanges
{
    public function __construct(
        private readonly PriceChangeValidator $validator,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array{added: int, updated: int, removed: int}
     */
    public function __invoke(int $priceListId, array $changes): array
    {
        return DB::transaction(function () use ($priceListId, $changes): array {
            $list = PriceList::query()->whereKey($priceListId)->lockForUpdate()->firstOrFail();
            $rows = $this->validator->validate($list, $changes); // again, under the lock

            $summary = ['added' => 0, 'updated' => 0, 'removed' => 0];
            $log = [];

            foreach ($rows as $row) {
                $existing = PriceListItem::query()
                    ->where('price_list_id', $list->id)->where('item_id', $row['item_id'])
                    ->where('uom_id', $row['uom_id'])->where('min_qty', $row['min_qty'])->first();

                if ($row['unit_price'] === null) {
                    if ($existing !== null) {
                        $log[] = [...$row, 'was' => $existing->unit_price];
                        $existing->delete();
                        $summary['removed']++;
                    }

                    continue;
                }

                if ($existing === null) {
                    PriceListItem::query()->create(['price_list_id' => $list->id, ...$row]);
                    $summary['added']++;
                    $log[] = [...$row, 'was' => null];
                } elseif (bccomp($existing->unit_price, $row['unit_price'], 6) !== 0) {
                    $log[] = [...$row, 'was' => $existing->unit_price];
                    $existing->forceFill(['unit_price' => $row['unit_price']])->save();
                    $summary['updated']++;
                }
            }

            if (array_sum($summary) > 0) {
                $list->forceFill(['version' => $list->version + 1])->save();
                $this->audit->record('price_list.prices_changed', $list, [], [], [
                    ...$summary,
                    'changes' => array_slice($log, 0, 200), // the full set is in the approval request when one was used
                ]);
                $this->outbox->record('masterdata.price_list.changed', ['price_list_id' => $list->id, 'code' => $list->code, ...$summary], 'price_list', $list->id);
            }

            return $summary;
        });
    }
}
