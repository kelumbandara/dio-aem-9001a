<?php

namespace App\Modules\Approvals\Models;

use App\Modules\Approvals\Policies\ApprovalRequestPolicy;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use App\Modules\Core\Contracts\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * State machine: pending → approved | rejected | expired | cancelled; approved → executed |
 * execution_failed (→ approved again on retry) | expired | cancelled.
 *
 * @property int $id
 * @property string $public_id
 * @property string $document_no
 * @property string $action_key
 * @property string $status
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string|null $amount decimal string
 * @property int $requested_by
 * @property int|null $branch_id
 * @property int $current_step_order
 * @property Carbon $expires_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $executed_at
 * @property Carbon|null $last_reminded_at
 * @property Carbon|null $created_at
 * @property array<string, mixed> $policy_snapshot
 * @property string $summary
 * @property string $channel
 * @property int $version
 */
#[RouteKey('public_id')]
#[UsePolicy(ApprovalRequestPolicy::class)]
#[Fillable([
    'document_no', 'action_key', 'policy_id', 'policy_snapshot', 'subject_type', 'subject_id', 'summary', 'payload',
    'payload_hash', 'amount', 'branch_id', 'requested_by', 'channel', 'ai_run_id', 'idempotency_key', 'status',
    'current_step_order', 'expires_at', 'decided_at', 'executed_at', 'last_reminded_at', 'version',
])]
class ApprovalRequest extends Model implements HasAttachments
{
    use Auditable, HasPublicId;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const EXECUTED = 'executed';

    public const EXECUTION_FAILED = 'execution_failed';

    /** Statuses in which the request can still change. */
    public const OPEN = [self::PENDING, self::APPROVED, self::EXECUTION_FAILED];

    protected function casts(): array
    {
        return [
            'policy_snapshot' => 'array',
            'payload' => 'encrypted:array', // may carry sensitive values such as bank account numbers
            'amount' => 'decimal:4',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** @return BelongsTo<ApprovalPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'policy_id');
    }

    /** @return HasMany<ApprovalRequestStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalRequestStep::class, 'request_id')->orderBy('step_order')->orderBy('id');
    }

    /** @return HasMany<ApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'request_id')->orderBy('id');
    }

    /** @return HasMany<ApprovalExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(ApprovalExecution::class, 'request_id')->orderBy('attempt');
    }
}
