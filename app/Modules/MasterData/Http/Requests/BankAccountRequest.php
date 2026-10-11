<?php

namespace App\Modules\MasterData\Http\Requests;

use App\Modules\MasterData\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class BankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('supplier') instanceof Supplier && $this->user()->can('manageBankAccounts', Supplier::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $deactivate = $this->isMethod('DELETE');

        return [
            'bank_name' => [$deactivate ? 'nullable' : 'required', 'string', 'max:150'],
            'branch_name' => ['nullable', 'string', 'max:150'],
            'account_name' => [$deactivate ? 'nullable' : 'required', 'string', 'max:150'],
            'account_no' => [$deactivate ? 'nullable' : 'required', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
