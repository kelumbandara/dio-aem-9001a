<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single place where the general ledger changes (docs/architecture/finance.md).
 *
 * One database transaction per journal: the entry, its lines, the numbering, the audit entry and the outbox event, or
 * nothing. A journal balances to the cent (checked here, and again by a deferred database trigger at commit), is
 * posted into an open period, and never changes afterwards; a correction is a reversal. People cannot post by hand to the
 * receivables, payables or inventory accounts: those move only through invoices, receipts, payments and stock documents,
 * which keeps them equal to their sub-ledgers.
 */
final class JournalPostingService implements JournalPoster
{
    private const MAX_LINES = 500;

    public function __construct(
        private readonly AccountMap $map,
        private readonly PeriodService $periods,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly PartyDirectory $parties,
    ) {}

    public function post(JournalRequest $request): PostedJournal
    {
        return DB::transaction(fn () => $this->run($request));
    }

    public function check(JournalRequest $request): PostedJournal
    {
        DB::beginTransaction();

        try {
            return $this->run($request);
        } finally {
            DB::rollBack();
        }
    }

    public function reverse(int $journalId, ?string $note = null, ?int $by = null): PostedJournal
    {
        return DB::transaction(function () use ($journalId, $note, $by): PostedJournal {
            $original = JournalEntry::query()->whereKey($journalId)->lockForUpdate()->first() ?? throw FinanceException::notFound('journal');

            if (Settlement::query()->where('journal_id', $original->id)->exists() || OpenItem::query()->where('journal_id', $original->id)->exists()) {
                throw FinanceException::make('finance.reverse_via_document', 'This journal belongs to an invoice, bill, receipt or payment; reverse that document instead.', 422);
            }

            if ($original->kind === 'system') {
                throw FinanceException::make('finance.system_journal', 'This journal was posted automatically for a business document; reverse that document instead.', 422);
            }

            return $this->undo($original, $note, $by, null, null);
        });
    }

    /** Reverse the journal owned by a receipt/payment/invoice when that document itself is reversed. */
    public function reverseOwned(int $journalId, ?string $note, ?int $by): PostedJournal
    {
        return DB::transaction(function () use ($journalId, $note, $by): PostedJournal {
            $original = JournalEntry::query()->whereKey($journalId)->lockForUpdate()->first() ?? throw FinanceException::notFound('journal');

            return $this->undo($original, $note, $by, null, null);
        });
    }

    /**
     * Post the opposite of a journal on today's date (or the first open day if today's period is closed).
     */
    private function undo(JournalEntry $original, ?string $note, ?int $by, ?string $sourceType, ?string $sourceId, bool $rollForward = false): PostedJournal
    {
        if ($original->kind === 'reversal') {
            throw FinanceException::make('finance.cannot_reverse_reversal', 'A reversal cannot itself be reversed; post a new journal instead.', 422);
        }
        if ($original->status === 'reversed') {
            throw FinanceException::make('finance.already_reversed', "{$original->journal_no} has already been reversed.", 409);
        }

        $today = $this->periods->today()->toDateString();
        [$period, $date] = $rollForward ? $this->periods->openPeriodRollingForward($today) : [$this->periods->openPeriodFor($today), $today];

        $lines = JournalLine::query()->where('entry_id', $original->id)->orderBy('line_no')->get();
        $entry = $this->create($period->id, $date, "Reversal of {$original->journal_no}".($note ? ": {$note}" : ''), $original->reference, $sourceType, $sourceId, 'reversal', $by, $original->total, $original->id,
            $lines->map(fn (JournalLine $l) => ['account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'description' => $l->description, 'party_type' => $l->party_type, 'party_id' => $l->party_id])->all(), $original->entry_date);

        $original->forceFill(['status' => 'reversed', 'reversed_by_id' => $entry->id])->save();
        $this->audit->record('finance.journal_reversed', $original, [], ['reversed_by' => $entry->journal_no, 'note' => $note]);

        return new PostedJournal($entry->id, $entry->public_id, $entry->journal_no, $entry->total, false);
    }

    private function run(JournalRequest $r): PostedJournal
    {
        $date = $this->validateDate($r->entryDate);
        if (! in_array($r->kind, ['manual', 'system', 'opening'], true)) {
            throw FinanceException::make('finance.kind_invalid', 'Unknown journal kind.', 422, 'kind');
        }
        $description = trim($r->description);
        if ($description === '' || mb_strlen($description) > 255) {
            throw FinanceException::make('finance.description_required', 'Describe the journal in up to 255 characters.', 422, 'description');
        }
        if (count($r->lines) < 2 || count($r->lines) > self::MAX_LINES) {
            throw FinanceException::make('finance.lines_invalid', 'A journal needs between 2 and '.self::MAX_LINES.' lines.', 422, 'lines');
        }

        if ($r->sourceType !== null && $r->sourceId !== null) {
            $existing = JournalEntry::query()->where('source_type', $r->sourceType)->where('source_id', $r->sourceId)->where('kind', '!=', 'reversal')->first();
            if ($existing !== null) {
                return new PostedJournal($existing->id, $existing->public_id, $existing->journal_no, $existing->total, true);
            }
        }

        $controlIds = $r->kind === 'system' ? [] : array_map(fn (string $key) => $this->map->idOrNull($key), ['receivable_control', 'payable_control', 'inventory']);
        $lines = [];
        $debits = $credits = '0';
        foreach ($r->lines as $i => $line) {
            $row = $this->resolveLine($line, "lines.{$i}");
            if (in_array($row['account_id'], $controlIds, true)) {
                throw FinanceException::make('finance.control_account', 'Receivables, payables and inventory are posted by invoices, receipts, payments and stock documents, not by hand.', 422, "lines.{$i}.account");
            }
            $debits = Decimal::add($debits, $row['debit']);
            $credits = Decimal::add($credits, $row['credit']);
            $lines[] = $row;
        }
        if (Decimal::compare($debits, $credits, 4) !== 0) {
            throw FinanceException::make('finance.unbalanced', "The journal does not balance: debits {$debits}, credits {$credits}.", 422, 'lines');
        }

        if ($r->rollForward) {
            [$period, $dateString] = $this->periods->openPeriodRollingForward($date->toDateString());
        } else {
            $period = $this->periods->openPeriodFor($date->toDateString());
            $dateString = $date->toDateString();
        }

        $entry = $this->create($period->id, $dateString, $description, $r->reference, $r->sourceType, $r->sourceId, $r->kind, $r->createdBy, Decimal::round($debits, 4), null, $lines, $dateString);

        return new PostedJournal($entry->id, $entry->public_id, $entry->journal_no, $entry->total, false);
    }

    /**
     * @param  list<array<string, mixed>>  $lines  account_id, debit, credit, description, party_type, party_id
     */
    private function create(int $periodId, string $date, string $description, ?string $reference, ?string $sourceType, ?string $sourceId, string $kind, ?int $by, string $total, ?int $reverses, array $lines, string $numberDate): JournalEntry
    {
        $this->numbering->define('finance.journal', 'JV/{FY}/{SEQ}', 5, 'fiscal_yearly');

        $entry = JournalEntry::query()->create([
            'journal_no' => $this->numbering->next('finance.journal', null, CarbonImmutable::parse($date)),
            'entry_date' => $date, 'period_id' => $periodId, 'status' => 'posted', 'kind' => $kind, 'description' => $description,
            'reference' => $reference, 'source_type' => $sourceType, 'source_id' => $sourceId, 'reverses_id' => $reverses, 'total' => $total, 'created_by' => $by,
        ]);

        foreach ($lines as $n => $l) {
            JournalLine::query()->create([
                'entry_id' => $entry->id, 'line_no' => $n + 1, 'account_id' => $l['account_id'], 'debit' => $l['debit'], 'credit' => $l['credit'],
                'description' => $l['description'] ?? null, 'party_type' => $l['party_type'] ?? null, 'party_id' => $l['party_id'] ?? null,
            ]);
        }

        $this->audit->record('finance.journal_posted', $entry, [], ['journal_no' => $entry->journal_no, 'kind' => $kind, 'total' => $total, 'lines' => count($lines), 'source' => $sourceType ? "{$sourceType}:{$sourceId}" : null]);
        $this->outbox->record('finance.journal.posted', [
            'journal_id' => $entry->id, 'journal_no' => $entry->journal_no, 'entry_date' => $date, 'kind' => $kind, 'total' => $total,
            'source_type' => $sourceType, 'source_id' => $sourceId,
        ], 'journal_entry', $entry->id);

        return $entry;
    }

    /** @return array{account_id: int, debit: string, credit: string, description: ?string, party_type: ?string, party_id: ?int} */
    private function resolveLine(JournalLineData $line, string $field): array
    {
        foreach (['debit' => $line->debit, 'credit' => $line->credit] as $side => $amount) {
            if (! Decimal::isDecimal($amount, 4) || Decimal::compare($amount, '0', 4) < 0) {
                throw FinanceException::make('finance.amount_invalid', 'Amounts must be zero or more with at most 4 decimals.', 422, "{$field}.{$side}");
            }
        }
        $hasDebit = Decimal::compare($line->debit, '0', 4) > 0;
        $hasCredit = Decimal::compare($line->credit, '0', 4) > 0;
        if ($hasDebit === $hasCredit) {
            throw FinanceException::make('finance.one_side', 'Each line is either a debit or a credit, with an amount above zero.', 422, "{$field}.debit");
        }

        $accountId = $line->accountId ?? ($line->accountKey !== null ? $this->map->id($line->accountKey) : null);
        $account = $accountId !== null ? Account::query()->find($accountId) : null;
        if ($account === null) {
            throw FinanceException::make('finance.account_invalid', 'Choose an account for every line.', 422, "{$field}.account");
        }
        if ($account->is_group || ! $account->is_active) {
            throw FinanceException::make('finance.account_invalid', "Account {$account->code} {$account->name} cannot be posted to (".($account->is_group ? 'it is a group' : 'it is inactive').').', 422, "{$field}.account");
        }

        if (($line->partyType === null) !== ($line->partyId === null)) {
            throw FinanceException::make('finance.party_invalid', 'Give both the party type and the party, or neither.', 422, "{$field}.party");
        }
        if ($line->partyType !== null) {
            $found = match ($line->partyType) {
                'customer' => $this->parties->customer($line->partyId),
                'supplier' => $this->parties->supplier($line->partyId),
                default => null,
            };
            if ($found === null) {
                throw FinanceException::make('finance.party_invalid', 'Unknown customer or supplier.', 422, "{$field}.party");
            }
        }

        return [
            'account_id' => $account->id, 'debit' => Decimal::round($line->debit, 4), 'credit' => Decimal::round($line->credit, 4),
            'description' => $line->description !== null ? mb_substr($line->description, 0, 255) : null, 'party_type' => $line->partyType, 'party_id' => $line->partyId,
        ];
    }

    private function validateDate(string $date): CarbonImmutable
    {
        try {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $date, (string) config('core.display_timezone'));
        } catch (\Throwable) {
            $d = null;
        }
        if ($d === null || $d->format('Y-m-d') !== $date || $d->year < 2000) {
            throw FinanceException::make('finance.date_invalid', 'Enter a valid date (YYYY-MM-DD).', 422, 'entry_date');
        }
        if ($d->gt($this->periods->today())) {
            throw FinanceException::make('finance.date_in_future', 'Journals cannot be dated in the future.', 422, 'entry_date');
        }

        return $d;
    }
}
