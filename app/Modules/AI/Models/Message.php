<?php

namespace App\Modules\AI\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property list<array{label: string, url: string}>|null $refs
 * @property int|null $execution_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['conversation_id', 'role', 'content', 'refs', 'execution_id'])]
class Message extends Model
{
    protected $table = 'ai_messages';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['refs' => 'array'];
    }
}
