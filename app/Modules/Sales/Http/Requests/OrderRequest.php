<?php

namespace App\Modules\Sales\Http\Requests;

class OrderRequest extends SalesDocumentRequest
{
    protected function permission(): string
    {
        return 'sales.order.manage';
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), ['warehouse' => ['required', 'string', 'max:20']]);
    }
}
