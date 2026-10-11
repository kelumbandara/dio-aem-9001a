<?php

namespace App\Modules\Core\Contracts;

/** Read access to company-wide facts that modules above Core need. */
interface CompanyInfo
{
    /** Month (1–12) in which the fiscal year starts; April (4) for Sri Lanka unless the company says otherwise. */
    public function fiscalYearStartMonth(): int;

    /**
     * Name, tax and contact details for document headers (invoices, reports).
     *
     * @return array{legal_name: string, trade_name: ?string, registration_no: ?string, tax_id: ?string, address: list<string>, phone: ?string, email: ?string, website: ?string}
     */
    public function profile(): array;
}
