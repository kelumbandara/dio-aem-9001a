<?php

namespace App\Modules\Approvals\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only (the database also refuses UPDATE/DELETE on PostgreSQL).
 *
 * @property int $decided_by
 * @property int|null $on_behalf_of
 * @property string $decision
 * @property string|null $comment
 * @property string $channel
 * @property Carbon $decided_at
 */
#[WithoutTimestamps]
#[Fillable(['request_id', 'step_id', 'decision', 'decided_by', 'on_behalf_of', 'comment', 'payload_hash', 'ip_address', 'channel', 'decided_at'])]
class ApprovalDecision extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Approval decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Approval decisions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
