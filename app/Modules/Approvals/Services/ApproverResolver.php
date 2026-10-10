<?php

namespace App\Modules\Approvals\Services;

use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalPolicyStep;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalRequestStep;
use App\Modules\Core\Contracts\UserDirectory;

/**
 * Who may decide a step. Rules, all enforced here (never in the UI):
 *  1. the person holds the step's role / is the named user, or holds an active delegation from such a person;
 *  2. the requester cannot approve their own request unless the policy allows it;
 *  3. with `distinct_approvers` one person cannot satisfy two steps of the same request;
 *  4. the authority matrix allows the decider (the delegator, for a delegate) to approve this amount.
 *
 * Roles are checked against {@see UserDirectory}, not through Laravel Gates, so `super-admin` gets no
 * shortcut: holding every permission does not make anyone an approver.
 */
final class ApproverResolver
{
    public function __construct(private readonly UserDirectory $users) {}

    /** @return array{actor: int, on_behalf_of: int|null}|null */
    public function deciderFor(ApprovalRequest $request, ApprovalRequestStep $step, int $actorId): ?array
    {
        $principals = $this->principals($request, $step);

        if (in_array($actorId, $principals, true)) {
            $onBehalfOf = null;
        } else {
            $delegator = ApprovalDelegation::query()
                ->active()
                ->where('delegate_id', $actorId)
                ->whereIn('delegator_id', $principals)
                ->where(fn ($q) => $q->whereNull('action_key')->orWhere('action_key', $request->action_key))
                ->orderBy('id')
                ->value('delegator_id');

            if ($delegator === null || ! $this->users->isActiveInternal($actorId)) {
                return null;
            }
            $onBehalfOf = (int) $delegator;
        }

        $holder = $onBehalfOf ?? $actorId;
        $flags = $request->policy_snapshot;

        if (! ($flags['allow_self_approval'] ?? false) && ($actorId === $request->requested_by || $holder === $request->requested_by)) {
            return null;
        }

        if (($flags['distinct_approvers'] ?? true) && $this->hasDecidedAnotherStep($request, $step, [$actorId, $holder])) {
            return null;
        }

        if (! $this->authorityAllows($holder, $request->action_key, $request->amount)) {
            return null;
        }

        return ['actor' => $actorId, 'on_behalf_of' => $onBehalfOf];
    }

    /**
     * Everyone who could decide this step right now (used for notifications and for refusing a policy
     * nobody can satisfy).
     *
     * @return list<int>
     */
    public function eligibleUserIds(ApprovalRequest $request, ApprovalRequestStep $step): array
    {
        $principals = $this->principals($request, $step);

        $delegates = ApprovalDelegation::query()
            ->active()
            ->whereIn('delegator_id', $principals)
            ->where(fn ($q) => $q->whereNull('action_key')->orWhere('action_key', $request->action_key))
            ->pluck('delegate_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $eligible = [];
        foreach (array_unique([...$principals, ...$delegates]) as $candidate) {
            if ($this->deciderFor($request, $step, $candidate) !== null) {
                $eligible[] = $candidate;
            }
        }

        return $eligible;
    }

    /** @return list<int> active users who hold the step directly (by role within the branch, or by name) */
    public function principals(ApprovalRequest $request, ApprovalRequestStep $step): array
    {
        if ($step->approver_type === ApprovalPolicyStep::TYPE_USER) {
            return $step->approver_user_id !== null && $this->users->isActiveInternal($step->approver_user_id)
                ? [$step->approver_user_id]
                : [];
        }

        return $this->users->userIdsWithRole((string) $step->approver_role, $request->branch_id);
    }

    /**
     * Does the authority matrix let `$userId` approve `$amount` of `$actionKey`?
     * An action with no matrix rows at all is not restricted by the matrix.
     */
    public function authorityAllows(int $userId, string $actionKey, ?string $amount): bool
    {
        $rows = ApprovalAuthority::query()->whereIn('action_key', [$actionKey, '*'])->get();
        if ($rows->isEmpty()) {
            return true;
        }

        $roles = $this->users->rolesOf($userId);

        foreach ($rows as $row) {
            $applies = $row->user_id === $userId || ($row->role_name !== null && in_array($row->role_name, $roles, true));
            if (! $applies) {
                continue;
            }
            if ($row->max_amount === null || $amount === null || bccomp($amount, $row->max_amount, 4) <= 0) {
                return true;
            }
        }

        return false;
    }

    /** Has the user taken part in the request at all (as requester, approver, delegate or decider)? */
    public function isInvolved(ApprovalRequest $request, int $userId): bool
    {
        if ($request->requested_by === $userId) {
            return true;
        }

        if (ApprovalDecision::query()->where('request_id', $request->id)->where(fn ($q) => $q->where('decided_by', $userId)->orWhere('on_behalf_of', $userId))->exists()) {
            return true;
        }

        foreach ($request->steps as $step) {
            $principals = $this->principals($request, $step);
            if (in_array($userId, $principals, true)) {
                return true;
            }
            if (ApprovalDelegation::query()->active()->where('delegate_id', $userId)->whereIn('delegator_id', $principals)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<int>  $people */
    private function hasDecidedAnotherStep(ApprovalRequest $request, ApprovalRequestStep $step, array $people): bool
    {
        return ApprovalDecision::query()
            ->where('request_id', $request->id)
            ->where('decision', 'approve')
            ->where('step_id', '<>', $step->id)
            ->where(fn ($q) => $q->whereIn('decided_by', $people)->orWhereIn('on_behalf_of', $people))
            ->exists();
    }
}
