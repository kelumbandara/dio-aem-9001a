<?php

namespace App\Modules\Approvals\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the authority matrix: a role or a user may approve `action_key` up to `max_amount`.
 *
 * @property string|null $role_name
 * @property int|null $user_id
 * @property string $action_key
 * @property string|null $max_amount decimal string; null = unlimited
 */
#[Table(name: 'approval_authority_matrix')]
#[Fillable(['role_name', 'user_id', 'action_key', 'max_amount', 'note'])]
class ApprovalAuthority extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['max_amount' => 'decimal:4'];
    }
}
