<?php

namespace App\Modules\Approvals\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $attempt
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon $executed_at
 */
#[WithoutTimestamps]
#[Fillable(['request_id', 'attempt', 'status', 'result', 'error_code', 'error_message', 'executed_at'])]
class ApprovalExecution extends Model
{
    protected function casts(): array
    {
        return ['result' => 'array', 'executed_at' => 'datetime'];
    }
}
