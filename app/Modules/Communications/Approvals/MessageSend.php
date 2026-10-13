<?php

namespace App\Modules\Communications\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Services\MessageService;

/**
 * Approval-gated action "send a sensitive message" (a payment reminder, anything that touches money or legal matters). Which
 * templates are sensitive is set per template; who must approve is set on the Approvals policy screens (a starting rule is seeded).
 * When the approval comes through the message is checked again before it is queued: an opt-out in the meantime stops it.
 */
final class MessageSend implements Approvable
{
    public const KEY = 'comm.message.send';

    public function __construct(private readonly MessageService $messages) {}

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
        return null;
    }

    public function summary(array $payload): string
    {
        return 'Send message: '.($payload['summary'] ?? '');
    }

    public function revalidate(array $payload, int $requestedBy): void
    {
        $m = Message::query()->find((int) ($payload['message_id'] ?? 0));
        if ($m === null || $m->status !== 'awaiting_approval') {
            throw CommunicationsException::make('comm.approval_stale', 'The message is no longer waiting for this approval (it was cancelled or changed).', 409);
        }
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        $m = $this->messages->sendApproved((int) $payload['message_id']);

        return ['message' => $m->public_id, 'status' => $m->status];
    }
}
