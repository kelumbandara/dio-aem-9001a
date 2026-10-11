<?php

namespace App\Modules\MasterData\Http\Requests;

use App\Modules\MasterData\Models\Supplier;

class SaveSupplierRequest extends SavePartyRequest
{
    protected function model(): string
    {
        return Supplier::class;
    }

    protected function routeParameter(): string
    {
        return 'supplier';
    }

    protected function extraRules(): array
    {
        return ['lead_time_days' => ['nullable', 'integer', 'between:0,3650']];
    }
}
