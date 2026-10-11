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

    public function profile(): array
    {
        $p = CompanyProfile::current();

        return [
            'legal_name' => $p !== null ? $p->legal_name : 'My Company (Pvt) Ltd', 'trade_name' => $p?->trade_name, 'registration_no' => $p?->registration_no, 'tax_id' => $p?->tax_id,
            'address' => array_values(array_filter([$p?->address_line1, $p?->address_line2, $p?->city, $p?->country], fn ($v) => $v !== null && $v !== '')),
            'phone' => $p?->phone, 'email' => $p?->email, 'website' => $p?->website,
        ];
    }
}
