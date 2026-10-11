<?php

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\DTOs\CreditNotePosting;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Support\Exceptions\BusinessException;

/** How Sales hands a credit note (a return, a price correction, a cancelled invoice) to Finance. */
interface CreditNotePoster
{
    /**
     * Post the journal and reduce what the customer owes. Posting the same source twice returns the first result.
     *
     * @throws BusinessException finance.* (period closed…) or masterdata.* (customer unknown)
     */
    public function postCreditNote(CreditNotePosting $note): PostedJournal;
}
