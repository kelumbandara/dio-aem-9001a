<?php

namespace App\Modules\Approvals\Database\Seeders;

use App\Modules\Approvals\Actions\SavePolicy;
use App\Modules\Approvals\Models\ApprovalPolicy;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Starting approval rules for purchase orders (the action key is Procurement's `procurement.po.approve`):
 *  - every order needs one approval by a "purchase-approver";
 *  - an order of LKR 500,000 or more also needs a "finance-approver" after that.
 * The amounts and approver roles are the owner's to change on the Approvals policy screens; this seeder only creates a
 * policy that is missing (it never overwrites or re-creates one that was edited or deactivated, matching by name).
 * Policies are tried by priority, lowest first, so the larger-order rule sits before the general one.
 */
class PurchaseOrderPolicySeeder extends Seeder
{
    public const KEY = 'procurement.po.approve';

    public function run(SavePolicy $savePolicy): void
    {
        foreach (['purchase-approver', 'finance-approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $common = [
            'action_key' => self::KEY, 'risk_levels' => null, 'max_amount' => null, 'branch_ids' => null, 'expiry_hours' => 72,
            'reminder_after_hours' => 24, 'allow_self_approval' => false, 'distinct_approvers' => true, 'is_active' => true,
        ];

        foreach ([
            ['name' => 'Purchase orders from LKR 500,000', 'priority' => 50, 'min_amount' => '500000',
                'steps' => [['order' => 1, 'type' => 'role', 'role' => 'purchase-approver', 'label' => 'Purchasing'], ['order' => 2, 'type' => 'role', 'role' => 'finance-approver', 'label' => 'Finance']]],
            ['name' => 'Purchase orders', 'priority' => 100, 'min_amount' => '0',
                'steps' => [['order' => 1, 'type' => 'role', 'role' => 'purchase-approver', 'label' => 'Purchasing']]],
        ] as $policy) {
            if (! ApprovalPolicy::query()->where('action_key', self::KEY)->where('name', $policy['name'])->exists()) {
                $savePolicy($common + $policy);
            }
        }
    }
}
