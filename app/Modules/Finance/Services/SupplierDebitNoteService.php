<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Finance\Contracts\DebitNotePoster;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\DebitNotePosting;
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

/** The supplier-side mirror of {@see CreditNoteService}. */
final class SupplierDebitNoteService implements DebitNotePoster
{
    public function __construct(
        private readonly JournalPoster $journals,
        private readonly SettlementService $settlements,
        private readonly PartyDirectory $parties,
        private readonly AuditLogger $audit,
    ) {}

    public function postDebitNote(DebitNotePosting $note): PostedJournal
    {
        return DB::transaction(function () use ($note): PostedJournal {
            $source = $note->sourceType !== null ? "debit_note:{$note->sourceType}" : null;
            if ($source !== null && $note->sourceId !== null) {
                $existing = JournalEntry::query()->where('source_type', $source)->where('source_id', $note->sourceId)->first();
                if ($existing !== null) {
                    return new PostedJournal($existing->id, $existing->public_id, $existing->journal_no, $existing->total, true);
                }
            }

            $supplier = $this->parties->supplier($note->supplierId) ?? throw FinanceException::make('finance.party_invalid', 'Unknown supplier.', 422, 'supplier');
            $number = trim($note->documentNo);
            if ($number === '' || mb_strlen($number) > 40) {
                throw FinanceException::make('finance.document_no_required', 'The debit note number is required (up to 40 characters).', 422, 'document_no');
            }
            if (Settlement::query()->where('document_no', $number)->exists()) {
                throw FinanceException::make('finance.document_exists', "A document numbered {$number} already exists.", 422, 'document_no');
            }
            if ($note->lines === []) {
                throw FinanceException::make('finance.lines_invalid', 'A debit note needs at least one line.', 422, 'lines');
            }

            // Credit lines give goods-received value, input VAT and expense back; a debit line is allowed for the one case where the
            // original bill had a favourable price variance. The supplier is credited with the difference, which must be above zero.
            $total = '0';
            foreach ($note->lines as $i => $line) {
                $amount = Decimal::sub($line->credit, $line->debit);
                if (! Decimal::isDecimal($line->credit, 4) || ! Decimal::isDecimal($line->debit, 4) || Decimal::compare($amount, '0', 4) === 0) {
                    throw FinanceException::make('finance.lines_invalid', 'Each line is a credit (or a debit for a favourable variance) above zero; the supplier line is added for you.', 422, "lines.{$i}");
                }
                $total = Decimal::add($total, $amount);
            }
            if (Decimal::compare($total, '0', 4) <= 0) {
                throw FinanceException::make('finance.lines_invalid', 'The debit note must come to more than zero.', 422, 'lines');
            }
            $total = Decimal::round($total, 4);

            $bill = null;
            if ($note->billNo !== null) {
                $bill = OpenItem::query()->where('side', OpenItem::PAYABLE)->where('party_id', $note->supplierId)->where('document_no', $note->billNo)->first()
                    ?? throw FinanceException::make('finance.open_item_invalid', "Bill {$note->billNo} was not found for {$supplier->name}.", 422, 'bill');
            }

            $posted = $this->journals->post(new JournalRequest(
                entryDate: $note->documentDate,
                description: "Debit note {$number} — {$supplier->name}".($note->reason ? " ({$note->reason})" : ''),
                lines: [JournalLineData::debit($total, 'payable_control', null, "Debit note {$number}", 'supplier', $note->supplierId), ...$note->lines],
                reference: $number,
                sourceType: $source,
                sourceId: $note->sourceId,
                kind: 'system',
                createdBy: $note->createdBy,
            ));

            $settlement = Settlement::query()->create([
                'side' => OpenItem::PAYABLE, 'document_no' => $number, 'party_id' => $note->supplierId, 'bank_account_id' => null,
                'settled_on' => CarbonImmutable::parse($note->documentDate)->toDateString(), 'amount' => $total, 'method' => 'debit_note',
                'reference' => $note->reason !== null ? mb_substr($note->reason, 0, 100) : null, 'status' => 'posted', 'journal_id' => $posted->id, 'created_by' => $note->createdBy,
            ]);

            if ($bill !== null && Decimal::compare($bill->open_amount, '0', 4) > 0) {
                $apply = Decimal::compare($total, $bill->open_amount, 4) < 0 ? $total : $bill->open_amount;
                $this->settlements->allocate($settlement, [['open_item_id' => $bill->id, 'amount' => Decimal::round($apply, 4)]]);
            }

            $this->audit->record('finance.debit_note_posted', $settlement, [], ['document_no' => $number, 'supplier_id' => $note->supplierId, 'amount' => $total, 'bill' => $note->billNo, 'journal_no' => $posted->journalNo]);

            return $posted;
        });
    }
}
