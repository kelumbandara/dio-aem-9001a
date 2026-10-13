<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\OpenItem;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Support\Decimal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Read models for receivables and payables: what is owed, how overdue, one party's ledger, and the tie-out to the ledger. */
final class SubledgerQueries
{
    private const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct(
        private readonly PartyDirectory $parties,
        private readonly AccountMap $map,
        private readonly FinancialReports $reports,
        private readonly PeriodService $periods,
    ) {}

    /**
     * Invoices or bills, oldest due first.
     *
     * @return LengthAwarePaginator<int, mixed>
     */
    public function openItems(string $side, ?int $partyId = null, bool $onlyOpen = true, int $perPage = 25): LengthAwarePaginator
    {
        $today = $this->periods->today()->toDateString();
        $page = OpenItem::query()->where('side', $side)->when($partyId, fn ($q, $v) => $q->where('party_id', $v))
            ->when($onlyOpen, fn ($q) => $q->where('open_amount', '>', 0))->orderBy('due_date')->orderBy('id')->paginate($perPage)->withQueryString();

        $names = $this->names($side, $page->getCollection()->pluck('party_id')->unique()->all());
        $page->setCollection($page->getCollection()->map(fn (OpenItem $i) => [
            'id' => $i->public_id, 'internal_id' => $i->id, 'party_id' => $i->party_id, 'party' => $names[$i->party_id] ?? '?', 'document_no' => $i->document_no,
            'document_date' => $i->document_date->toDateString(), 'due_date' => $i->due_date->toDateString(), 'amount' => $i->amount, 'open_amount' => $i->open_amount,
            'days_overdue' => max(0, (int) floor((strtotime($today) - $i->due_date->getTimestamp()) / 86400)),
        ]));

        return $page;
    }

    /**
     * Who owes what, by how long it is overdue (as at today, from what is still open).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function ageing(string $side): array
    {
        $today = strtotime($this->periods->today()->toDateString());
        $byParty = [];
        foreach (OpenItem::query()->where('side', $side)->where('open_amount', '>', 0)->get() as $i) {
            $days = (int) floor(($today - $i->due_date->getTimestamp()) / 86400);
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 30 => 'd1_30', $days <= 60 => 'd31_60', $days <= 90 => 'd61_90', default => 'd90_plus',
            };
            $byParty[$i->party_id] ??= array_fill_keys(self::BUCKETS, '0');
            $byParty[$i->party_id][$bucket] = Decimal::add($byParty[$i->party_id][$bucket], $i->open_amount);
        }

        $names = $this->names($side, array_keys($byParty));
        $rows = [];
        $totals = array_fill_keys([...self::BUCKETS, 'total'], '0');
        foreach ($byParty as $partyId => $b) {
            $row = ['party_id' => $partyId, 'party' => $names[$partyId] ?? '?'];
            $sum = '0';
            foreach (self::BUCKETS as $k) {
                $row[$k] = Decimal::round($b[$k], 4);
                $sum = Decimal::add($sum, $b[$k]);
                $totals[$k] = Decimal::add($totals[$k], $b[$k]);
            }
            $row['total'] = Decimal::round($sum, 4);
            $totals['total'] = Decimal::add($totals['total'], $sum);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => strcmp($a['party'], $b['party']));

        return ['rows' => $rows, 'totals' => array_map(fn ($v) => Decimal::round($v, 4), $totals)];
    }

    /**
     * Everything posted for one customer/supplier on the control account, with a running balance owed.
     *
     * @return array{party: string, opening: string, lines: list<array<string, mixed>>, closing: string}
     */
    public function partyLedger(string $side, int $partyId, ?string $from, string $to): array
    {
        $receivable = $side === OpenItem::RECEIVABLE;
        $accountId = $this->map->id($receivable ? 'receivable_control' : 'payable_control');
        $type = $receivable ? 'customer' : 'supplier';
        $sign = fn (string $debit, string $credit) => $receivable ? Decimal::sub($debit, $credit) : Decimal::sub($credit, $debit);

        $base = fn () => DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.account_id', $accountId)->where('l.party_type', $type)->where('l.party_id', $partyId);

        $opening = '0';
        if ($from !== null) {
            $o = $base()->where('e.entry_date', '<', $from)->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();
            $opening = $sign((string) $o->d, (string) $o->c);
        }

        $balance = $opening;
        $lines = [];
        foreach ($base()->when($from !== null, fn ($q) => $q->where('e.entry_date', '>=', $from))->where('e.entry_date', '<=', $to)
            ->orderBy('e.entry_date')->orderBy('e.id')->get(['e.entry_date', 'e.public_id', 'e.journal_no', 'e.description', 'l.debit', 'l.credit']) as $r) {
            $balance = Decimal::add($balance, $sign((string) $r->debit, (string) $r->credit));
            $lines[] = [
                'date' => $r->entry_date, 'journal_id' => $r->public_id, 'journal_no' => $r->journal_no, 'description' => $r->description,
                'debit' => Decimal::round((string) $r->debit, 4), 'credit' => Decimal::round((string) $r->credit, 4), 'balance' => Decimal::round($balance, 4),
            ];
        }

        return ['party' => $this->names($side, [$partyId])[$partyId] ?? '?', 'opening' => Decimal::round($opening, 4), 'lines' => $lines, 'closing' => Decimal::round($balance, 4)];
    }

    /**
     * Does the sub-ledger (what invoices/bills are still open, less money received/paid but not yet allocated) equal the
     * control account in the general ledger?
     *
     * @return array{subledger: string, control: string, difference: string}
     */
    public function tieOut(string $side): array
    {
        $open = (string) OpenItem::query()->where('side', $side)->sum('open_amount');
        $unallocated = (string) (DB::selectOne(
            "SELECT COALESCE(SUM(s.amount - COALESCE((SELECT SUM(a.amount) FROM settlement_allocations a WHERE a.settlement_id = s.id), 0)), 0) AS u
             FROM settlements s WHERE s.side = ? AND s.status = 'posted'",
            [$side],
        )->u ?? '0');

        $subledger = Decimal::sub($open, $unallocated);
        $net = $this->reports->balanceOf($this->map->id($side === OpenItem::RECEIVABLE ? 'receivable_control' : 'payable_control'));
        $control = $side === OpenItem::RECEIVABLE ? $net : Decimal::sub('0', $net);

        return ['subledger' => Decimal::round($subledger, 4), 'control' => Decimal::round($control, 4), 'difference' => Decimal::round(Decimal::sub($subledger, $control), 4)];
    }

    /**
     * Receipts/payments with money not yet allocated.
     *
     * @return list<array<string, mixed>>
     */
    public function unallocated(string $side): array
    {
        $rows = DB::select(
            "SELECT s.public_id, s.document_no, s.party_id, s.settled_on, s.amount, s.amount - COALESCE((SELECT SUM(a.amount) FROM settlement_allocations a WHERE a.settlement_id = s.id), 0) AS unallocated
             FROM settlements s WHERE s.side = ? AND s.status = 'posted' ORDER BY s.settled_on, s.id",
            [$side],
        );
        $names = $this->names($side, array_values(array_unique(array_map(fn ($r) => (int) $r->party_id, $rows))));

        return array_values(array_map(fn ($r) => [
            'id' => $r->public_id, 'document_no' => $r->document_no, 'party' => $names[(int) $r->party_id] ?? '?', 'date' => $r->settled_on,
            'amount' => Decimal::round((string) $r->amount, 4), 'unallocated' => Decimal::round((string) $r->unallocated, 4),
        ], array_filter($rows, fn ($r) => Decimal::compare((string) $r->unallocated, '0', 4) > 0)));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> party id => "CODE — name"
     */
    public function names(string $side, array $ids): array
    {
        $parties = $side === OpenItem::RECEIVABLE ? $this->parties->customers($ids) : $this->parties->suppliers($ids);
        $out = [];
        foreach ($ids as $id) {
            $p = $parties[(int) $id] ?? null;
            $out[(int) $id] = $p !== null ? "{$p->code} — {$p->name}" : '?';
        }

        return $out;
    }
}
