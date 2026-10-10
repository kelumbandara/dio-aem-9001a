<?php

namespace App\Modules\Approvals\Database\Seeders;

use App\Modules\Approvals\Actions\SavePolicy;
use App\Modules\Approvals\Demo\DemoExpense;
use App\Modules\Approvals\Models\ApprovalPolicy;
use Illuminate\Database\Seeder;

/**
 * Development-only: a policy for the demo action (demo expenses from LKR 1,000 need one approval by
 * role "finance-approver"). Run after Core's DemoStaffSeeder, which creates that role and its people.
 * Refuses to run outside `local`.
 */
class DemoApprovalSeeder extends Seeder
{
    public function run(SavePolicy $savePolicy): void
    {
        if (! app()->environment('local')) {
            return;
        }

        if (ApprovalPolicy::query()->where('action_key', DemoExpense::KEY)->exists()) {
            return;
        }

        $savePolicy([
            'name' => 'Demo expenses from LKR 1,000',
            'action_key' => DemoExpense::KEY,
            'risk_levels' => null,
            'min_amount' => '1000',
            'max_amount' => null,
            'branch_ids' => null,
            'priority' => 100,
            'expiry_hours' => 72,
            'reminder_after_hours' => 24,
            'allow_self_approval' => false,
            'distinct_approvers' => true,
            'is_active' => true,
            'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver', 'label' => 'Finance']],
        ]);
    }
}
