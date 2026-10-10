<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $event_id
 * @property string $event_type
 * @property string|null $aggregate_type
 * @property string|null $aggregate_id
 * @property array<string, mixed> $payload
 * @property string|null $correlation_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property int|null $branch_id
 * @property Carbon $occurred_at
 * @property Carbon $available_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $failed_at
 * @property int $attempts
 */
#[Unguarded]
class OutboxEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
