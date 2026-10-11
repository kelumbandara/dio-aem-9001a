<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\OpenItem;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Read-only proof that the books agree with themselves: every journal balances, the whole ledger balances, open items are
 * consistent with what was settled, and receivables/payables equal their control accounts in the general ledger.
 * (Stock against the inventory account is compared across modules by a test and, later, by Reporting: Finance never reads
 * Inventory's tables.)
 */
final class FinanceReconciler
{
    public function __construct(private readonly SubledgerQueries $queries) {}

    /** @return list<string> one sentence per discrepancy; empty when everything agrees */
    public function discrepancies(int $limit = 200): array
    {
        $problems = [];

        foreach (DB::select(<<<'SQL'
            SELECT e.journal_no, e.total, COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c
            FROM journal_entries e LEFT JOIN journal_lines l ON l.entry_id = e.id
            GROUP BY e.id, e.journal_no, e.total HAVING COALESCE(SUM(l.debit), 0) <> COALESCE(SUM(l.credit), 0) OR COALESCE(SUM(l.debit), 0) <> e.total LIMIT ?
        SQL, [$limit]) as $r) {
            $problems[] = "Journal {$r->journal_no} does not balance (debits {$r->d}, credits {$r->c}, total {$r->total}).";
        }

        $all = DB::selectOne('SELECT COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c FROM journal_lines');
        if (Decimal::compare((string) $all->d, (string) $all->c, 4) !== 0) {
            $problems[] = "The ledger does not balance: total debits {$all->d}, total credits {$all->c}.";
        }

        foreach (DB::select(<<<'SQL'
            SELECT i.id, i.document_no, i.amount, i.open_amount,
                   i.amount - COALESCE((SELECT SUM(a.amount) FROM settlement_allocations a JOIN settlements s ON s.id = a.settlement_id WHERE a.open_item_id = i.id AND s.status = 'posted'), 0) AS expected
            FROM open_items i
            WHERE i.open_amount <> i.amount - COALESCE((SELECT SUM(a.amount) FROM settlement_allocations a JOIN settlements s ON s.id = a.settlement_id WHERE a.open_item_id = i.id AND s.status = 'posted'), 0) LIMIT ?
        SQL, [$limit]) as $r) {
            $problems[] = "Open item {$r->document_no}: open amount is {$r->open_amount} but its settlements say {$r->expected}.";
        }

        foreach ([OpenItem::RECEIVABLE, OpenItem::PAYABLE] as $side) {
            $t = $this->queries->tieOut($side);
            if (Decimal::compare($t['difference'], '0', 4) !== 0) {
                $problems[] = ucfirst($side)."s: the sub-ledger says {$t['subledger']} but the control account says {$t['control']} (difference {$t['difference']}).";
            }
        }

        return $problems;
    }
}
