<?php

namespace App\Modules\MasterData\Http\Requests;

use App\Modules\MasterData\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;

class SavePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $list = $this->route('priceList');

        return $list instanceof PriceList ? $this->user()->can('update', $list) : $this->user()->can('create', PriceList::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = ! $this->route('priceList');
        $required = $create ? 'required' : 'sometimes';

        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._\\-]+$/'],
            'name' => [$required, 'string', 'max:150'],
            'type' => [$required, 'in:sales,purchase'],
            'currency' => [$required, 'string', 'size:3'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
        ];
    }
}
