<?php

namespace App\Modules\Core\Contracts;

/** Read access to company-wide facts that modules above Core need. */
interface CompanyInfo
{
    /** Month (1–12) in which the fiscal year starts; April (4) for Sri Lanka unless the company says otherwise. */
    public function fiscalYearStartMonth(): int;
}
