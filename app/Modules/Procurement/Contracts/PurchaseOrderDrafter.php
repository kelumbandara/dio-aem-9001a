<?php

namespace App\Modules\Procurement\Contracts;

use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Lets the AI assistant (or any helper acting for a person) prepare a **draft** purchase order. It is never submitted: submitting (and
 * so the approval rules) stays with a person on the order's page. The draft carries a visible "drafted by the AI assistant" note.
 */
interface PurchaseOrderDrafter
{
    /**
     * @param  array<string, mixed>  $data  supplier, warehouse and items by code, notes?, lines [{item, qty, unit_price?}]; anything else is ignored
     * @return array{id: string, document_no: string, supplier: string, lines: int, net_total: string, gross_total: string, status: string}
     *
     * @throws BusinessException procurement.forbidden when the person may not manage purchase orders; the usual order errors otherwise
     */
    public function draft(Authorizable $actor, int $userId, array $data): array;
}
