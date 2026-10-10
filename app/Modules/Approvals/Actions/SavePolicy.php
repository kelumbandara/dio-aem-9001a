<?php

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalPolicyStep;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Core\Contracts\UserDirectory;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/** Creates or updates a policy together with its steps, in one transaction. */
final class SavePolicy
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly ApprovableRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $data  policy columns plus `steps`: list of {order, type, role|user_id, label}
     */
    public function __invoke(array $data, ?ApprovalPolicy $policy = null): ApprovalPolicy
    {
        if (! $this->registry->has($data['action_key'])) {
            throw new BusinessException('approvals.unknown_action', "No action with key '{$data['action_key']}' can be gated.", 422, [['field' => 'action_key', 'message' => 'Unknown action.']]);
        }

        $steps = $this->validatedSteps($data['steps'] ?? []);
        unset($data['steps']);

        if (($data['min_amount'] ?? null) !== null && ($data['max_amount'] ?? null) !== null && bccomp((string) $data['min_amount'], (string) $data['max_amount'], 4) > 0) {
            throw new BusinessException('approvals.invalid_range', 'Minimum amount cannot exceed the maximum.', 422, [['field' => 'min_amount', 'message' => 'Minimum amount cannot exceed the maximum.']]);
        }

        return DB::transaction(function () use ($data, $steps, $policy): ApprovalPolicy {
            $policy ??= new ApprovalPolicy;
            $policy->fill($data)->save();

            $policy->steps()->delete();
            foreach ($steps as $step) {
                $policy->steps()->create($step);
            }

            return $policy->load('steps');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function validatedSteps(array $steps): array
    {
        if ($steps === []) {
            throw new BusinessException('approvals.steps_required', 'A policy needs at least one approval step.', 422, [['field' => 'steps', 'message' => 'Add at least one step.']]);
        }

        $roles = $this->users->roleNames();
        $clean = [];

        foreach (array_values($steps) as $i => $step) {
            $type = $step['type'] ?? null;
            $row = ['step_order' => (int) $step['order'], 'approver_type' => $type, 'label' => $step['label'] ?? null,
                'approver_role' => null, 'approver_user_id' => null];

            if ($type === ApprovalPolicyStep::TYPE_ROLE) {
                if (! in_array($step['role'] ?? null, $roles, true)) {
                    throw new BusinessException('approvals.unknown_role', 'Unknown role in step '.($i + 1).'.', 422, [['field' => "steps.{$i}.role", 'message' => 'Unknown role.']]);
                }
                $row['approver_role'] = $step['role'];
            } elseif ($type === ApprovalPolicyStep::TYPE_USER) {
                if (! $this->users->isActiveInternal((int) ($step['user_id'] ?? 0))) {
                    throw new BusinessException('approvals.unknown_user', 'Unknown or inactive user in step '.($i + 1).'.', 422, [['field' => "steps.{$i}.user_id", 'message' => 'Unknown or inactive user.']]);
                }
                $row['approver_user_id'] = (int) $step['user_id'];
            } else {
                throw new BusinessException('approvals.invalid_step', 'Invalid approver type in step '.($i + 1).'.', 422);
            }

            $clean[] = $row;
        }

        return $clean;
    }
}
