<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table(name: 'company_profile', incrementing: false)]
#[Fillable([
    'legal_name', 'trade_name', 'registration_no', 'tax_id', 'address_line1', 'address_line2', 'city',
    'country', 'phone', 'email', 'website', 'base_currency', 'timezone', 'fiscal_year_start_month', 'logo_path',
])]
class CompanyProfile extends Model
{
    use Auditable;

    public const ID = 1;

    public static function current(): ?self
    {
        return static::query()->find(self::ID);
    }

    protected function casts(): array
    {
        return ['fiscal_year_start_month' => 'integer'];
    }
}
