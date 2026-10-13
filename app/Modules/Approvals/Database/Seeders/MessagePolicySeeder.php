<?php

namespace App\Modules\Approvals\Database\Seeders;

use App\Modules\Approvals\Actions\SavePolicy;
use App\Modules\Approvals\Models\ApprovalPolicy;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * A starting approval rule for sensitive messages (the action key is Communications' `comm.message.send`): one approval by a
 * "finance-approver" before a payment reminder or a free-text notice is sent. The owner changes who approves on the Approvals policy
 * screens; this seeder only creates the policy when it is missing (matching by name), never overwriting one that was edited or switched off.
 */
class MessagePolicySeeder extends Seeder
{
    public const KEY = 'comm.message.send';

    public function run(SavePolicy $savePolicy): void
    {
        Role::findOrCreate('finance-approver', 'web');
        $name = 'Sensitive messages';
        if (! ApprovalPolicy::query()->where('action_key', self::KEY)->where('name', $name)->exists()) {
            $savePolicy([
                'name' => $name, 'action_key' => self::KEY, 'priority' => 100, 'risk_levels' => null, 'min_amount' => null, 'max_amount' => null, 'branch_ids' => null, 'expiry_hours' => 48,
                'reminder_after_hours' => 12, 'allow_self_approval' => false, 'distinct_approvers' => true, 'is_active' => true,
                'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver', 'label' => 'Finance']],
            ]);
        }
    }
}
