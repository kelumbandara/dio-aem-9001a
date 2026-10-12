<?php

namespace App\Modules\Sales\Contracts;

use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Lets the AI assistant (or any helper acting for a person) prepare a **draft** quotation. Only a draft is ever made: it is not sent,
 * accepted or turned into an order, and it carries a visible "drafted by the AI assistant" note. The helper cannot set prices or
 * discounts: lines are priced from the customer's price list, exactly as when a person leaves the price empty.
 */
interface QuotationDrafter
{
    /**
     * @param  array<string, mixed>  $data  customer (code), notes?, lines [{item (code), qty}]; anything else is ignored
     * @return array{id: string, document_no: string, customer: string, lines: int, net_total: string, gross_total: string, status: string}
     *
     * @throws BusinessException sales.forbidden when the person may not manage quotations; the usual quotation errors otherwise
     */
    public function draft(Authorizable $actor, int $userId, array $data): array;
}
