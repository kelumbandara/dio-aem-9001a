<?php

namespace App\Modules\Finance\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\JournalRequest;

/** Approval-gated action "post a manual journal". With no policy for this key the journal is posted at once. */
final class ManualJournalPost implements Approvable
{
    public const KEY = 'finance.journal.post';

    public function __construct(private readonly JournalPoster $journals) {}

    public static function approvalKey(): string
    {
        return self::KEY;
    }

    public function riskLevel(): string
    {
        return 'R3';
    }

    public function amount(array $payload): ?string
    {
        return $payload['total'] ?? null;
    }

    public function summary(array $payload): string
    {
        return 'Manual journal "'.($payload['journal']['description'] ?? '').'" for LKR '.($payload['total'] ?? '0');
    }

    public function revalidate(array $payload, int $requestedBy): void
    {
        $this->journals->check($this->request($payload, $requestedBy));
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        $posted = $this->journals->post($this->request($payload, $token->requestedBy));

        return ['journal_id' => $posted->id, 'journal_no' => $posted->journalNo];
    }

    /** @param  array<string, mixed>  $payload */
    private function request(array $payload, int $by): JournalRequest
    {
        $r = JournalRequest::fromArray($payload['journal']);

        return new JournalRequest($r->entryDate, $r->description, $r->lines, $r->reference, null, null, 'manual', $by);
    }
}
