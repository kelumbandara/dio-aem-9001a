<?php

namespace App\Modules\MasterData\Http\Resources;

use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Supplier;
use Illuminate\Http\Request;

/** @mixin Supplier */
class SupplierResource extends PartyResource
{
    protected function extra(Request $request): array
    {
        $full = $request->user()?->can('viewBankDetails', Supplier::class) ?? false;

        return [
            'lead_time_days' => $this->lead_time_days,
            // Full numbers only for holders of masterdata.supplier.bank.view; everyone else sees the last 4 digits.
            'bank_accounts' => $this->bankAccounts->map(fn ($b) => [
                'id' => $b->id, 'bank_name' => $b->bank_name, 'branch_name' => $b->branch_name, 'account_name' => $b->account_name,
                'account_no' => $full ? $b->account_no : $b->maskedAccountNo(),
                'currency' => $b->currency_id ? Currency::query()->whereKey($b->currency_id)->value('code') : null,
                'is_default' => $b->is_default, 'is_active' => $b->is_active,
            ])->values(),
        ];
    }
}
