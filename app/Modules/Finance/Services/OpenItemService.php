<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Posts customer invoices and supplier bills: the journal (with the control account line) and the open item. */
final class OpenItemService implements OpenItemPoster
{
    public function __construct(
        private readonly JournalPoster $journals,
        private readonly PartyDirectory $parties,
        private readonly AuditLogger $audit,
    ) {}

    public function postInvoice(InvoicePosting $invoice): PostedJournal
    {
        return DB::transaction(function () use ($invoice): PostedJournal {
            $receivable = $invoice->side === OpenItem::RECEIVABLE;
            if (! $receivable && $invoice->side !== OpenItem::PAYABLE) {
                throw FinanceException::make('finance.side_invalid', 'The side must be receivable or payable.', 422, 'side');
            }

            if ($invoice->sourceType !== null && $invoice->sourceId !== null) {
                $existing = OpenItem::query()->where('side', $invoice->side)->where('source_type', $invoice->sourceType)->where('source_id', $invoice->sourceId)->first();
                if ($existing !== null) {
                    $journal = JournalEntry::query()->findOrFail($existing->journal_id);

                    return new PostedJournal($journal->id, $journal->public_id, $journal->journal_no, $journal->total, true);
                }
            }

            $party = $receivable ? $this->parties->requireActiveCustomer($invoice->partyId) : $this->parties->requireActiveSupplier($invoice->partyId);
            $documentNo = trim($invoice->documentNo);
            if ($documentNo === '' || mb_strlen($documentNo) > 60) {
                throw FinanceException::make('finance.document_no_required', 'The document number is required (up to 60 characters).', 422, 'document_no');
            }
            [$date, $due] = [$this->date($invoice->documentDate, 'document_date'), $this->date($invoice->dueDate, 'due_date')];
            if ($due->lt($date)) {
                throw FinanceException::make('finance.due_before_date', 'The due date cannot be before the document date.', 422, 'due_date');
            }
            if (OpenItem::query()->where('side', $invoice->side)->where('party_id', $invoice->partyId)->where('document_no', $documentNo)->exists()) {
                throw FinanceException::make('finance.document_exists', "{$party->name} already has a document numbered {$documentNo}.", 422, 'document_no');
            }
            if ($invoice->lines === []) {
                throw FinanceException::make('finance.lines_invalid', 'An invoice needs at least one line.', 422, 'lines');
            }

            $total = '0';
            foreach ($invoice->lines as $i => $line) {
                $amount = $receivable ? $line->credit : $line->debit;
                $other = $receivable ? $line->debit : $line->credit;
                if (Decimal::compare($other, '0', 4) !== 0 || ! Decimal::isDecimal($amount, 4) || Decimal::compare($amount, '0', 4) <= 0) {
                    throw FinanceException::make('finance.lines_invalid', 'Give '.($receivable ? 'credit' : 'debit').' lines only, each above zero; the party line is added for you.', 422, "lines.{$i}");
                }
                $total = Decimal::add($total, $amount);
            }
            $total = Decimal::round($total, 4);

            $partyType = $receivable ? 'customer' : 'supplier';
            $control = $receivable
                ? JournalLineData::debit($total, 'receivable_control', null, "Invoice {$documentNo}", $partyType, $invoice->partyId)
                : JournalLineData::credit($total, 'payable_control', null, "Bill {$documentNo}", $partyType, $invoice->partyId);

            $posted = $this->journals->post(new JournalRequest(
                entryDate: $invoice->documentDate,
                description: $invoice->description ?? (($receivable ? 'Invoice ' : 'Bill ').$documentNo.' — '.$party->name),
                lines: [$control, ...$invoice->lines],
                reference: $documentNo,
                sourceType: $invoice->sourceType !== null ? $invoice->side.':'.$invoice->sourceType : null,
                sourceId: $invoice->sourceId,
                kind: 'system',
                createdBy: $invoice->createdBy,
            ));

            $item = OpenItem::query()->create([
                'side' => $invoice->side, 'party_id' => $invoice->partyId, 'document_no' => $documentNo,
                'document_date' => $date->toDateString(), 'due_date' => $due->toDateString(), 'amount' => $total, 'open_amount' => $total,
                'source_type' => $invoice->sourceType, 'source_id' => $invoice->sourceId, 'journal_id' => $posted->id,
            ]);
            $this->audit->record($receivable ? 'finance.invoice_posted' : 'finance.bill_posted', $item, [], [
                'document_no' => $documentNo, 'party_id' => $invoice->partyId, 'amount' => $total, 'journal_no' => $posted->journalNo,
            ]);

            return $posted;
        });
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('core.display_timezone'));
        } catch (\Throwable) {
            $d = null;
        }
        if ($d === null || $d->format('Y-m-d') !== $value) {
            throw FinanceException::make('finance.date_invalid', 'Enter a valid date (YYYY-MM-DD).', 422, $field);
        }

        return $d;
    }
}
