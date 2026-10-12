<?php

namespace App\Modules\AI\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $conversation_id
 * @property int $user_id
 * @property string $agent
 * @property string $model
 * @property string $prompt_hash
 * @property string $status
 * @property int $steps
 * @property list<array<string, mixed>> $tool_calls
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property string|null $error_code
 * @property int $duration_ms
 * @property CarbonImmutable $created_at
 */
#[RouteKey('public_id')]
#[Fillable(['conversation_id', 'user_id', 'agent', 'model', 'prompt_hash', 'status', 'steps', 'tool_calls', 'input_tokens', 'output_tokens', 'cost_usd', 'error_code', 'duration_ms'])]
class Execution extends Model
{
    use HasPublicId;

    protected $table = 'ai_executions';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['tool_calls' => 'array'];
    }
}
