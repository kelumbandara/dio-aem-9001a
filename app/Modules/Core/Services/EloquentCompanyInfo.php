<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\CompanyInfo;
use App\Modules\Core\Models\CompanyProfile;

final class EloquentCompanyInfo implements CompanyInfo
{
    public function fiscalYearStartMonth(): int
    {
        $month = (int) (CompanyProfile::query()->value('fiscal_year_start_month') ?? 4);

        return $month >= 1 && $month <= 12 ? $month : 4;
    }
}
