<?php

namespace App\Modules\AI\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $title
 * @property string $channel
 * @property string $agent
 * @property CarbonImmutable $updated_at
 */
#[RouteKey('public_id')]
#[Fillable(['user_id', 'title', 'channel', 'agent'])]
class Conversation extends Model
{
    use HasPublicId;

    protected $table = 'ai_conversations';

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('id');
    }
}
