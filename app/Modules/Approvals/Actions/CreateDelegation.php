<?php

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Core\Contracts\UserDirectory;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;

/** Hands approval rights to someone else for a limited period. Authority limits remain the delegator's. */
final class CreateDelegation
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly ApprovableRegistry $registry,
    ) {}

    public function __invoke(
        int $delegatorId,
        int $delegateId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $actionKey = null,
        ?string $reason = null,
    ): ApprovalDelegation {
        if ($delegatorId === $delegateId) {
            throw new BusinessException('approvals.invalid_delegation', 'You cannot delegate to yourself.', 422, [['field' => 'delegate_id', 'message' => 'Choose someone else.']]);
        }
        if (! $this->users->isActiveInternal($delegateId)) {
            throw new BusinessException('approvals.unknown_user', 'The delegate must be an active user.', 422, [['field' => 'delegate_id', 'message' => 'Unknown or inactive user.']]);
        }
        if ($endsAt <= $startsAt) {
            throw new BusinessException('approvals.invalid_delegation', 'The end must be after the start.', 422, [['field' => 'ends_at', 'message' => 'The end must be after the start.']]);
        }
        if ($endsAt > $startsAt->addDays((int) config('approvals.delegation_max_days'))) {
            throw new BusinessException('approvals.delegation_too_long', 'A delegation can last at most '.config('approvals.delegation_max_days').' days.', 422, [['field' => 'ends_at', 'message' => 'Too long.']]);
        }
        if ($endsAt <= now()) {
            throw new BusinessException('approvals.invalid_delegation', 'The delegation would already be over.', 422, [['field' => 'ends_at', 'message' => 'Must be in the future.']]);
        }
        if ($actionKey !== null && ! $this->registry->has($actionKey)) {
            throw new BusinessException('approvals.unknown_action', 'Unknown action.', 422, [['field' => 'action_key', 'message' => 'Unknown action.']]);
        }

        return ApprovalDelegation::query()->create([
            'delegator_id' => $delegatorId,
            'delegate_id' => $delegateId,
            'action_key' => $actionKey,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $reason,
        ]);
    }
}
