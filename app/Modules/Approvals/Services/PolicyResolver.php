<?php

namespace App\Modules\Approvals\Services;

use App\Modules\Approvals\Models\ApprovalPolicy;

/** Picks the policy (if any) that applies to an action: first match by priority, then id. */
final class PolicyResolver
{
    public function resolve(string $actionKey, ?string $amount, ?int $branchId, string $riskLevel): ?ApprovalPolicy
    {
        $policies = ApprovalPolicy::query()
            ->where('action_key', $actionKey)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($policies as $policy) {
            if ($this->matches($policy, $amount, $branchId, $riskLevel)) {
                return $policy->load('steps');
            }
        }

        return null;
    }

    private function matches(ApprovalPolicy $policy, ?string $amount, ?int $branchId, string $riskLevel): bool
    {
        if ($policy->risk_levels !== null && $policy->risk_levels !== [] && ! in_array($riskLevel, $policy->risk_levels, true)) {
            return false;
        }

        if ($policy->branch_ids !== null && $policy->branch_ids !== [] && ($branchId === null || ! in_array($branchId, $policy->branch_ids, true))) {
            return false;
        }

        // A policy with amount bounds only governs payloads that have an amount.
        if ($policy->min_amount !== null) {
            if ($amount === null || bccomp($amount, $policy->min_amount, 4) < 0) {
                return false;
            }
        }
        if ($policy->max_amount !== null) {
            if ($amount === null || bccomp($amount, $policy->max_amount, 4) > 0) {
                return false;
            }
        }

        return true;
    }
}
