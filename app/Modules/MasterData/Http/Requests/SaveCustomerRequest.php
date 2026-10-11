<?php

namespace App\Modules\MasterData\Http\Requests;

use App\Modules\MasterData\Models\Customer;

class SaveCustomerRequest extends SavePartyRequest
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function routeParameter(): string
    {
        return 'customer';
    }

    protected function extraRules(): array
    {
        return ['credit_limit' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,4})?$/']];
    }
}
