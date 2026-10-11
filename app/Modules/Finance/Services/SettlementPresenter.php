<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Models\SettlementAllocation;
use Illuminate\Support\Collection;

/** The readable form of a receipt or payment (shared by the API and the web screens). */
final class SettlementPresenter
{
    public function __construct(private readonly SubledgerQueries $queries, private readonly SettlementService $service) {}

    /**
     * @param  Collection<int, Settlement>  $settlements
     * @return list<array<string, mixed>>
     */
    public function many(Collection $settlements): array
    {
        return $settlements->map(fn (Settlement $s) => $this->one($s, false))->values()->all();
    }

    /** @return array<string, mixed> */
    public function one(Settlement $s, bool $withAllocations = true): array
    {
        $party = $this->queries->names($s->side, [$s->party_id])[$s->party_id] ?? '?';
        $out = [
            'id' => $s->public_id, 'side' => $s->side, 'document_no' => $s->document_no, 'party' => $party, 'date' => $s->settled_on->toDateString(), 'amount' => $s->amount,
            'unallocated' => $this->service->unallocated($s), 'method' => $s->method, 'reference' => $s->reference, 'notes' => $s->notes, 'status' => $s->status,
            'bank_account' => $s->bankAccount->name ?? null,
        ];
        if ($withAllocations) {
            $items = OpenItem::query()->whereIn('id', SettlementAllocation::query()->where('settlement_id', $s->id)->pluck('open_item_id'))->get()->keyBy('id');
            $out['allocations'] = SettlementAllocation::query()->where('settlement_id', $s->id)->orderBy('id')->get()
                ->map(fn ($a) => ['open_item' => $items[$a->open_item_id]->public_id, 'document_no' => $items[$a->open_item_id]->document_no, 'amount' => $a->amount])->values()->all();
        }

        return $out;
    }
}
