<?php

namespace App\Modules\Finance\DTOs;

/**
 * A sales credit note: takes value back from what a customer owes. `lines` are the DEBIT lines (revenue, VAT, SSCL,
 * NBT being given back); Finance adds the credit on the receivables control account for the total, tagged with the
 * customer, records the credit note as a non-cash settlement, and — when `invoiceNo` names an open invoice of the customer
 * — settles that invoice with it (up to what is still open); any remainder stays as credit on account.
 */
final readonly class CreditNotePosting
{
    /** @param  list<JournalLineData>  $lines */
    public function __construct(
        public int $customerId,
        public string $documentNo,     // the credit note number (CN/…)
        public string $documentDate,   // Y-m-d
        public array $lines,
        public ?string $invoiceNo = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?string $reason = null,
        public ?int $createdBy = null,
    ) {}
}
