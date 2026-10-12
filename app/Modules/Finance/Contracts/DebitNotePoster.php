<?php

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\DTOs\DebitNotePosting;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Support\Exceptions\BusinessException;

/** How Procurement hands a supplier debit note to Finance (journal + a non-cash settlement of the bill). */
interface DebitNotePoster
{
    /**
     * Posting the same source twice returns the first result.
     *
     * @throws BusinessException finance.* (period closed, unknown bill…) or masterdata.*
     */
    public function postDebitNote(DebitNotePosting $note): PostedJournal;
}
