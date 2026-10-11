<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Models\SettlementAllocation;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receipts from customers and payments to suppliers, settling invoices and bills.
 *
 * A receipt/payment may settle several open items, partly or fully, or none (money "on account"); what is not yet
 * allocated can be allocated later. Open items are locked in id order, so two people settling the same invoice at once
 * can never settle more than it is worth. Reversing a receipt/payment reverses its journal and gives the settled
 * amounts back to the invoices.
 */
final class SettlementService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly JournalPoster $poster,
        private readonly NumberingService $numbering,
        private readonly PartyDirectory $parties,
        private readonly PeriodService $periods,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $in  side, party_id, bank_account_id, date, amount, method, reference, notes, allocations: list<{open_item_id, amount}>
     */
    public function post(array $in, ?int $by = null): Settlement
    {
        return DB::transaction(function () use ($in, $by): Settlement {
            $side = (string) ($in['side'] ?? '');
            if (! in_array($side, [OpenItem::RECEIVABLE, OpenItem::PAYABLE], true)) {
                throw FinanceException::make('finance.side_invalid', 'The side must be receivable or payable.', 422, 'side');
            }
            $receivable = $side === OpenItem::RECEIVABLE;
            $partyId = (int) ($in['party_id'] ?? 0);
            $party = $receivable ? $this->parties->customer($partyId) : $this->parties->supplier($partyId);
            if ($party === null) {
                throw FinanceException::make('finance.party_invalid', 'Unknown '.($receivable ? 'customer' : 'supplier').'.', 422, 'party');
            }

            $bank = BankAccount::query()->with('account')->find((int) ($in['bank_account_id'] ?? 0));
            if ($bank === null || ! $bank->is_active) {
                throw FinanceException::make('finance.bank_invalid', 'Choose an active bank or cash account.', 422, 'bank_account');
            }
            if ($bank->currency !== 'LKR') {
                throw FinanceException::make('finance.foreign_bank_not_supported', "{$bank->name} is a {$bank->currency} account; receipts and payments in foreign currency are not supported yet.", 422, 'bank_account');
            }

            $amount = (string) ($in['amount'] ?? '');
            if (! Decimal::isDecimal($amount, 4) || Decimal::compare($amount, '0', 4) <= 0) {
                throw FinanceException::make('finance.amount_invalid', 'Enter an amount above zero with at most 4 decimals.', 422, 'amount');
            }
            $method = (string) ($in['method'] ?? 'bank_transfer');
            if (! in_array($method, Settlement::METHODS, true)) {
                throw FinanceException::make('finance.method_invalid', 'Choose cash, cheque, bank transfer, card or other.', 422, 'method');
            }
            $date = (string) ($in['date'] ?? $this->periods->today()->toDateString());

            $allocations = $this->normaliseAllocations((array) ($in['allocations'] ?? []));
            $items = $this->lockItems($allocations, $side, $partyId);
            $allocated = $this->checkAllocations($allocations, $items, $amount);

            $key = $receivable ? 'receipt' : 'payment';
            $this->numbering->define("finance.{$key}", ($receivable ? 'REC' : 'PAY').'/{FY}/{SEQ}', 5, 'fiscal_yearly');
            $uuid = (string) Str::uuid();
            $label = $receivable ? 'Receipt' : 'Payment';

            $journal = $this->poster->post(new JournalRequest(
                entryDate: $date,
                description: "{$label} ".($receivable ? 'from ' : 'to ').$party->name.($in['reference'] ?? null ? " ({$in['reference']})" : ''),
                lines: $receivable
                    ? [JournalLineData::debit($amount, null, $bank->account_id, $bank->name), JournalLineData::credit($amount, 'receivable_control', null, $label, 'customer', $partyId)]
                    : [JournalLineData::debit($amount, 'payable_control', null, $label, 'supplier', $partyId), JournalLineData::credit($amount, null, $bank->account_id, $bank->name)],
                reference: $in['reference'] ?? null,
                sourceType: "settlement:{$side}", sourceId: $uuid, kind: 'system', createdBy: $by,
            ));

            $settlement = Settlement::query()->create([
                'public_id' => $uuid, 'side' => $side, 'document_no' => $this->numbering->next("finance.{$key}", null, CarbonImmutable::parse($date)),
                'party_id' => $partyId, 'bank_account_id' => $bank->id, 'settled_on' => $date, 'amount' => Decimal::round($amount, 4), 'method' => $method,
                'reference' => $this->text($in['reference'] ?? null, 100), 'notes' => $this->text($in['notes'] ?? null, 500), 'status' => 'posted',
                'journal_id' => $journal->id, 'created_by' => $by,
            ]);

            $this->apply($settlement, $allocations, $items);
            $this->audit->record($receivable ? 'finance.receipt_posted' : 'finance.payment_posted', $settlement, [], [
                'document_no' => $settlement->document_no, 'party_id' => $partyId, 'amount' => $settlement->amount, 'allocated' => $allocated, 'journal_no' => $journal->journalNo,
            ]);

            return $settlement->refresh();
        });
    }

    /**
     * Settle open items with money already received/paid but not yet allocated.
     *
     * @param  list<array{open_item_id: int, amount: string}>  $allocations
     */
    public function allocate(Settlement $settlement, array $allocations): Settlement
    {
        return DB::transaction(function () use ($settlement, $allocations): Settlement {
            $settlement = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'posted') {
                throw FinanceException::make('finance.settlement_reversed', 'This document has been reversed.', 422);
            }
            $allocations = $this->normaliseAllocations($allocations);
            $items = $this->lockItems($allocations, $settlement->side, $settlement->party_id);
            $remaining = $this->unallocated($settlement);
            $this->checkAllocations($allocations, $items, $remaining);
            $this->apply($settlement, $allocations, $items);
            $this->audit->record('finance.settlement_allocated', $settlement, [], ['document_no' => $settlement->document_no, 'allocations' => count($allocations)]);

            return $settlement->refresh();
        });
    }

    public function reverse(Settlement $settlement, ?string $note = null, ?int $by = null): Settlement
    {
        return DB::transaction(function () use ($settlement, $note, $by): Settlement {
            $settlement = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'posted') {
                throw FinanceException::make('finance.already_reversed', "{$settlement->document_no} has already been reversed.", 409);
            }

            $allocations = SettlementAllocation::query()->where('settlement_id', $settlement->id)->orderBy('open_item_id')->get();
            $items = OpenItem::query()->whereIn('id', $allocations->pluck('open_item_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $this->journals->reverseOwned($settlement->journal_id, $note, $by);

            foreach ($allocations as $a) {
                $item = $items[$a->open_item_id];
                $item->forceFill(['open_amount' => Decimal::add($item->open_amount, $a->amount)])->save();
            }
            $settlement->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('finance.settlement_reversed', $settlement, [], ['document_no' => $settlement->document_no, 'note' => $note]);

            return $settlement->refresh();
        });
    }

    /** Money received/paid but not yet allocated to any invoice/bill (zero for a reversed document). */
    public function unallocated(Settlement $settlement): string
    {
        if ($settlement->status !== 'posted') {
            return '0.0000';
        }
        $allocated = (string) SettlementAllocation::query()->where('settlement_id', $settlement->id)->sum('amount');

        return Decimal::round(Decimal::sub($settlement->amount, $allocated), 4);
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return list<array{open_item_id: int, amount: string}>
     */
    private function normaliseAllocations(array $rows): array
    {
        $merged = [];
        foreach ($rows as $i => $row) {
            $id = (int) ($row['open_item_id'] ?? 0);
            $amount = (string) ($row['amount'] ?? '');
            if ($id === 0 && ($amount === '' || $amount === '0')) {
                continue;   // an empty form row
            }
            if (! Decimal::isDecimal($amount, 4) || Decimal::compare($amount, '0', 4) <= 0) {
                throw FinanceException::make('finance.amount_invalid', 'Each allocation needs an amount above zero with at most 4 decimals.', 422, "allocations.{$i}.amount");
            }
            $merged[$id] = Decimal::add($merged[$id] ?? '0', $amount);
        }
        ksort($merged);

        return array_map(fn (int $id, string $amount) => ['open_item_id' => $id, 'amount' => Decimal::round($amount, 4)], array_keys($merged), $merged);
    }

    /**
     * @param  list<array{open_item_id: int, amount: string}>  $allocations
     * @return array<int, OpenItem>
     */
    private function lockItems(array $allocations, string $side, int $partyId): array
    {
        $ids = array_column($allocations, 'open_item_id');
        $items = OpenItem::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();

        foreach ($allocations as $i => $a) {
            $item = $items[$a['open_item_id']] ?? null;
            if ($item === null || $item->side !== $side || $item->party_id !== $partyId) {
                throw FinanceException::make('finance.open_item_invalid', 'That invoice or bill does not belong to this '.($side === OpenItem::RECEIVABLE ? 'customer' : 'supplier').'.', 422, "allocations.{$i}.open_item_id");
            }
        }

        return $items;
    }

    /**
     * @param  list<array{open_item_id: int, amount: string}>  $allocations
     * @param  array<int, OpenItem>  $items
     */
    private function checkAllocations(array $allocations, array $items, string $available): string
    {
        $total = '0';
        foreach ($allocations as $i => $a) {
            if (Decimal::compare($a['amount'], $items[$a['open_item_id']]->open_amount, 4) > 0) {
                throw FinanceException::make('finance.over_allocated', "{$items[$a['open_item_id']]->document_no} only has {$items[$a['open_item_id']]->open_amount} left to settle.", 422, "allocations.{$i}.amount");
            }
            $total = Decimal::add($total, $a['amount']);
        }
        if (Decimal::compare($total, $available, 4) > 0) {
            throw FinanceException::make('finance.over_allocated', "The allocations ({$total}) are more than the amount available ({$available}).", 422, 'allocations');
        }

        return Decimal::round($total, 4);
    }

    /**
     * @param  list<array{open_item_id: int, amount: string}>  $allocations
     * @param  array<int, OpenItem>  $items
     */
    private function apply(Settlement $settlement, array $allocations, array $items): void
    {
        foreach ($allocations as $a) {
            $item = $items[$a['open_item_id']];
            SettlementAllocation::query()->create(['settlement_id' => $settlement->id, 'open_item_id' => $item->id, 'amount' => $a['amount']]);
            $item->forceFill(['open_amount' => Decimal::sub($item->open_amount, $a['amount'])])->save();
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
