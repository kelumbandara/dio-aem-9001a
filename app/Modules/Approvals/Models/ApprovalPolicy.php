<?php

namespace App\Modules\Approvals\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $public_id
 * @property string $action_key
 * @property list<string>|null $risk_levels
 * @property list<int>|null $branch_ids
 * @property string|null $min_amount decimal string
 * @property string|null $max_amount decimal string
 * @property string $name
 * @property int $expiry_hours
 * @property int $reminder_after_hours
 * @property bool $allow_self_approval
 * @property bool $distinct_approvers
 */
#[RouteKey('public_id')]
#[Fillable([
    'name', 'action_key', 'risk_levels', 'min_amount', 'max_amount', 'branch_ids', 'priority', 'expiry_hours',
    'reminder_after_hours', 'allow_self_approval', 'distinct_approvers', 'is_active',
])]
class ApprovalPolicy extends Model
{
    use Auditable, HasPublicId, SoftDeletes;

    protected function casts(): array
    {
        return [
            'risk_levels' => 'array',
            'branch_ids' => 'array',
            'allow_self_approval' => 'boolean',
            'distinct_approvers' => 'boolean',
            'is_active' => 'boolean',
            // amounts stay strings (decimal:4) – never floats
            'min_amount' => 'decimal:4',
            'max_amount' => 'decimal:4',
        ];
    }

    /** @return HasMany<ApprovalPolicyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalPolicyStep::class, 'policy_id')->orderBy('step_order')->orderBy('id');
    }
}
