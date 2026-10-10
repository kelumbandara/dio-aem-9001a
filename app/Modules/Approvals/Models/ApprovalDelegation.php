<?php

namespace App\Modules\Approvals\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $delegator_id
 * @property int $delegate_id
 * @property string|null $action_key
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['delegator_id', 'delegate_id', 'action_key', 'starts_at', 'ends_at', 'reason', 'revoked_at'])]
class ApprovalDelegation extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @param  Builder<ApprovalDelegation>  $query
     * @return Builder<ApprovalDelegation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }
}
