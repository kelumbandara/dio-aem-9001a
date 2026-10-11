<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Finance\Contracts\CreditNotePoster;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\CreditNotePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes: the journal (revenue, VAT and levies given back against receivables) and a non-cash settlement that
 * reduces what the customer owes — against the named invoice when there is one, otherwise (or for the remainder) as
 * credit on account that can be allocated later like any receipt.
 */
final class CreditNoteService implements CreditNotePoster
{
    public function __construct(
        private readonly JournalPoster $journals,
        private readonly SettlementService $settlements,
        private readonly PartyDirectory $parties,
        private readonly AuditLogger $audit,
    ) {}

    public function postCreditNote(CreditNotePosting $note): PostedJournal
    {
        return DB::transaction(function () use ($note): PostedJournal {
            $source = $note->sourceType !== null ? "credit_note:{$note->sourceType}" : null;
            if ($source !== null && $note->sourceId !== null) {
                $existing = JournalEntry::query()->where('source_type', $source)->where('source_id', $note->sourceId)->first();
                if ($existing !== null) {
                    return new PostedJournal($existing->id, $existing->public_id, $existing->journal_no, $existing->total, true);
                }
            }

            $customer = $this->parties->customer($note->customerId) ?? throw FinanceException::make('finance.party_invalid', 'Unknown customer.', 422, 'customer');
            $number = trim($note->documentNo);
            if ($number === '' || mb_strlen($number) > 40) {
                throw FinanceException::make('finance.document_no_required', 'The credit note number is required (up to 40 characters).', 422, 'document_no');
            }
            if (Settlement::query()->where('document_no', $number)->exists()) {
                throw FinanceException::make('finance.document_exists', "A document numbered {$number} already exists.", 422, 'document_no');
            }
            if ($note->lines === []) {
                throw FinanceException::make('finance.lines_invalid', 'A credit note needs at least one line.', 422, 'lines');
            }

            $total = '0';
            foreach ($note->lines as $i => $line) {
                if (Decimal::compare($line->credit, '0', 4) !== 0 || ! Decimal::isDecimal($line->debit, 4) || Decimal::compare($line->debit, '0', 4) <= 0) {
                    throw FinanceException::make('finance.lines_invalid', 'Give debit lines only, each above zero; the customer line is added for you.', 422, "lines.{$i}");
                }
                $total = Decimal::add($total, $line->debit);
            }
            $total = Decimal::round($total, 4);

            $invoice = null;
            if ($note->invoiceNo !== null) {
                $invoice = OpenItem::query()->where('side', OpenItem::RECEIVABLE)->where('party_id', $note->customerId)->where('document_no', $note->invoiceNo)->first()
                    ?? throw FinanceException::make('finance.open_item_invalid', "Invoice {$note->invoiceNo} was not found for {$customer->name}.", 422, 'invoice');
            }

            $posted = $this->journals->post(new JournalRequest(
                entryDate: $note->documentDate,
                description: "Credit note {$number} — {$customer->name}".($note->reason ? " ({$note->reason})" : ''),
                lines: [...$note->lines, JournalLineData::credit($total, 'receivable_control', null, "Credit note {$number}", 'customer', $note->customerId)],
                reference: $number,
                sourceType: $source,
                sourceId: $note->sourceId,
                kind: 'system',
                createdBy: $note->createdBy,
            ));

            $settlement = Settlement::query()->create([
                'side' => OpenItem::RECEIVABLE, 'document_no' => $number, 'party_id' => $note->customerId, 'bank_account_id' => null,
                'settled_on' => CarbonImmutable::parse($note->documentDate)->toDateString(), 'amount' => $total, 'method' => 'credit_note',
                'reference' => $note->reason !== null ? mb_substr($note->reason, 0, 100) : null, 'status' => 'posted', 'journal_id' => $posted->id, 'created_by' => $note->createdBy,
            ]);

            if ($invoice !== null && Decimal::compare($invoice->open_amount, '0', 4) > 0) {
                $apply = Decimal::compare($total, $invoice->open_amount, 4) < 0 ? $total : $invoice->open_amount;
                $this->settlements->allocate($settlement, [['open_item_id' => $invoice->id, 'amount' => Decimal::round($apply, 4)]]);
            }

            $this->audit->record('finance.credit_note_posted', $settlement, [], ['document_no' => $number, 'customer_id' => $note->customerId, 'amount' => $total, 'invoice' => $note->invoiceNo, 'journal_no' => $posted->journalNo]);

            return $posted;
        });
    }
}
