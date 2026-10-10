<?php

namespace App\Modules\Approvals\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $request_id
 * @property int $step_order
 * @property string $approver_type
 * @property string|null $approver_role
 * @property int|null $approver_user_id
 * @property string $status
 * @property string|null $label
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
#[Fillable([
    'request_id', 'step_order', 'approver_type', 'approver_role', 'approver_user_id', 'label', 'status',
    'decided_by', 'decided_at',
])]
class ApprovalRequestStep extends Model
{
    public const WAITING = 'waiting';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SKIPPED = 'skipped';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }
}
