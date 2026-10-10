<?php

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Core\Contracts\UserDirectory;
use App\Support\Exceptions\BusinessException;

final class SaveAuthority
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly ApprovableRegistry $registry,
    ) {}

    /**
     * @param  array{role_name?: ?string, user_id?: ?int, action_key: string, max_amount?: ?string, note?: ?string}  $data
     */
    public function __invoke(array $data): ApprovalAuthority
    {
        $role = $data['role_name'] ?? null;
        $userId = $data['user_id'] ?? null;

        if (($role === null) === ($userId === null)) {
            throw new BusinessException('approvals.invalid_holder', 'Choose either a role or a user.', 422, [['field' => 'role_name', 'message' => 'Choose either a role or a user.']]);
        }
        if ($role !== null && ! in_array($role, $this->users->roleNames(), true)) {
            throw new BusinessException('approvals.unknown_role', 'Unknown role.', 422, [['field' => 'role_name', 'message' => 'Unknown role.']]);
        }
        if ($userId !== null && ! $this->users->isActiveInternal($userId)) {
            throw new BusinessException('approvals.unknown_user', 'Unknown or inactive user.', 422, [['field' => 'user_id', 'message' => 'Unknown or inactive user.']]);
        }
        if ($data['action_key'] !== '*' && ! $this->registry->has($data['action_key'])) {
            throw new BusinessException('approvals.unknown_action', 'Unknown action.', 422, [['field' => 'action_key', 'message' => 'Unknown action.']]);
        }

        return ApprovalAuthority::query()->create([
            'role_name' => $role,
            'user_id' => $userId,
            'action_key' => $data['action_key'],
            'max_amount' => $data['max_amount'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
    }
}
