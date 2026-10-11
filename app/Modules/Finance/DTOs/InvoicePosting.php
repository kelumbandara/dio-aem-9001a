<?php

namespace App\Modules\Finance\DTOs;

/**
 * A sales invoice (side receivable) or a supplier bill (side payable) to post.
 *
 * `lines` are the sides OTHER than the customer/supplier: for a sales invoice the credits (revenue, VAT, SSCL, NBT),
 * for a supplier bill the debits (inventory/GRNI, expenses, input VAT). Finance adds the opposite line on the
 * receivables / payables control account for the total, tagged with the party, and creates the open item.
 */
final readonly class InvoicePosting
{
    /** @param  list<JournalLineData>  $lines */
    public function __construct(
        public string $side,              // 'receivable' | 'payable'
        public int $partyId,              // customers.id or suppliers.id
        public string $documentNo,        // the invoice/bill number
        public string $documentDate,      // Y-m-d
        public string $dueDate,           // Y-m-d
        public array $lines,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?string $description = null,
        public ?int $createdBy = null,
    ) {}
}
