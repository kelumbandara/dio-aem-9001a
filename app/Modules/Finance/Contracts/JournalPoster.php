<?php

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Support\Exceptions\BusinessException;

/**
 * The only way the general ledger changes. Manual journals, automatic postings from other modules, receipts and
 * payments all go through this: balanced, in an open period, atomic, immutable once posted.
 */
interface JournalPoster
{
    /**
     * @throws BusinessException finance.unbalanced | finance.period_closed | finance.mapping_missing | finance.account_invalid …
     */
    public function post(JournalRequest $request): PostedJournal;

    /** Run exactly the checks of post(), then undo everything: what would happen, saved nowhere. */
    public function check(JournalRequest $request): PostedJournal;

    /**
     * Undo a posted journal with an opposite one (the original stays, marked reversed).
     *
     * @throws BusinessException finance.journal_not_found | finance.already_reversed | finance.period_closed
     */
    public function reverse(int $journalId, ?string $note = null, ?int $by = null): PostedJournal;
}
