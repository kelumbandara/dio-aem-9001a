<?php

namespace App\Modules\Manufacturing\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $order_id
 * @property int $machine_id
 * @property int $shift_id
 * @property int $reason_id
 * @property CarbonImmutable $shift_date
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $ended_at
 * @property string $minutes
 * @property string|null $notes
 * @property string $source
 * @property string|null $external_event_id
 * @property int|null $reported_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $voided_by
 */
#[RouteKey('public_id')]
#[Fillable(['order_id', 'machine_id', 'shift_id', 'reason_id', 'shift_date', 'started_at', 'ended_at', 'minutes', 'notes', 'source', 'external_event_id', 'reported_by', 'voided_at', 'void_reason', 'voided_by'])]
class DowntimeEvent extends Model
{
    use HasPublicId;

    protected $table = 'downtime_events';

    public const UPDATED_AT = null;

    /** @return BelongsTo<DowntimeReason, $this> */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(DowntimeReason::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    protected function casts(): array
    {
        return ['shift_date' => 'immutable_date', 'started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'minutes' => 'decimal:2', 'voided_at' => 'immutable_datetime'];
    }
}
