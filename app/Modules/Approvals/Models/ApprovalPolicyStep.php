<?php

namespace App\Modules\Approvals\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $step_order
 * @property string $approver_type
 * @property string|null $approver_role
 * @property int|null $approver_user_id
 * @property string|null $label
 */
// Steps are replaced together with their policy; the policy's audit entry records the change.
#[Fillable(['policy_id', 'step_order', 'approver_type', 'approver_role', 'approver_user_id', 'label'])]
class ApprovalPolicyStep extends Model
{
    public const TYPE_ROLE = 'role';

    public const TYPE_USER = 'user';

    /** @return BelongsTo<ApprovalPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'policy_id');
    }
}
