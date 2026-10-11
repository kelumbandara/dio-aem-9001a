<?php

namespace App\Modules\Sales\Http\Requests;

class QuotationRequest extends SalesDocumentRequest
{
    protected function permission(): string
    {
        return 'sales.quotation.manage';
    }
}
