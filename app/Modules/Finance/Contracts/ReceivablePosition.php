<?php

namespace App\Modules\Finance\Contracts;

/** Read access to what customers owe, and to the bank details printed on invoices, for Sales. */
interface ReceivablePosition
{
    /**
     * What the customer owes right now (open invoices less money and credit received but not yet allocated), in LKR,
     * as a decimal string. Never below zero: credit on account does not give a negative exposure.
     */
    public function outstanding(int $customerId): string;

    /** The part of the outstanding amount that is past its due date. */
    public function overdue(int $customerId): string;

    /**
     * Bank accounts shown on invoices (Finance → setup → "show on invoices"), with the full account number.
     *
     * @return list<array{name: string, bank_name: ?string, account_no: ?string, currency: string}>
     */
    public function invoiceBankAccounts(): array;
}
