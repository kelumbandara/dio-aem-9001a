<?php

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Support\Exceptions\BusinessException;

/** How Sales and Procurement hand their posted invoices and bills to Finance (journal + the open item to be settled). */
interface OpenItemPoster
{
    /**
     * Post the journal and record what the customer/supplier owes. Posting the same source twice returns the first result.
     *
     * @throws BusinessException finance.* (period closed, unbalanced…) or masterdata.* (party inactive)
     */
    public function postInvoice(InvoicePosting $invoice): PostedJournal;
}
