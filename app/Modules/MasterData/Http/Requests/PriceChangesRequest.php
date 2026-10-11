<?php

namespace App\Modules\MasterData\Http\Requests;

use App\Modules\MasterData\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;

class PriceChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $list = $this->route('priceList');

        return $list instanceof PriceList && $this->user()->can('update', $list);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:1000'],
            'changes.*.item' => ['required', 'string', 'max:40'],
            'changes.*.uom' => ['nullable', 'string', 'max:10'],
            'changes.*.min_qty' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            // null/empty removes the price row
            'changes.*.unit_price' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ];
    }
}
