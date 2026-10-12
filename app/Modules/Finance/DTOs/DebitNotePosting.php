<?php

namespace App\Modules\Finance\DTOs;

/**
 * A supplier debit note: goods sent back or a price corrected after the bill. `lines` are the CREDIT lines (goods
 * received not invoiced, input VAT, expense given back); Finance adds the debit on the payables control account
 * tagged with the supplier, which reduces what is owed — against `billNo` when given.
 */
final readonly class DebitNotePosting
{
    /** @param  list<JournalLineData>  $lines */
    public function __construct(
        public int $supplierId,
        public string $documentNo,
        public string $documentDate,
        public array $lines,
        public ?string $billNo = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?string $reason = null,
        public ?int $createdBy = null,
    ) {}
}
