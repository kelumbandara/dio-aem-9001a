<?php

namespace App\Modules\Finance\Actions;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Finance\Approvals\ManualJournalPost;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\DTOs\PostedJournal;

/** Entry point for people posting a journal by hand: checked first, then posted at once or sent for approval if a policy says so. */
final class PostManualJournal
{
    public function __construct(private readonly JournalPoster $journals, private readonly ApprovalService $approvals) {}

    /** @return PostedJournal|array{approval_request_id: string, document_no: string} */
    public function __invoke(JournalRequest $request, int $by, string $channel = 'web', ?string $idempotencyKey = null): PostedJournal|array
    {
        $request = new JournalRequest($request->entryDate, $request->description, $request->lines, $request->reference, null, null, 'manual', $by);

        $preview = $this->journals->check($request);   // a bad journal is refused now, not queued for approval
        $decision = $this->approvals->gate(
            ManualJournalPost::KEY,
            ['journal' => $request->toArray(), 'total' => $preview->total],
            $by,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
        );

        if ($decision->requiresApproval) {
            return ['approval_request_id' => (string) $decision->requestId, 'document_no' => (string) $decision->documentNo];
        }

        return $this->journals->post($request);
    }
}
