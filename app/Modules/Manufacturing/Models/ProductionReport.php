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
 * @property int $order_id
 * @property int|null $operation_id
 * @property int $shift_id
 * @property int|null $machine_id
 * @property string $kind
 * @property CarbonImmutable $report_date
 * @property string $produced_qty
 * @property string $reject_qty
 * @property string $rework_qty
 * @property string|null $notes
 * @property string $source
 * @property string|null $external_event_id
 * @property int|null $reported_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $voided_by
 */
#[RouteKey('public_id')]
#[Fillable(['order_id', 'operation_id', 'shift_id', 'machine_id', 'kind', 'report_date', 'produced_qty', 'reject_qty', 'rework_qty', 'notes', 'source', 'external_event_id', 'reported_by', 'voided_at', 'void_reason', 'voided_by'])]
class ProductionReport extends Model
{
    use HasPublicId;

    protected $table = 'production_reports';

    public const UPDATED_AT = null;

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    protected function casts(): array
    {
        return ['report_date' => 'immutable_date', 'produced_qty' => 'decimal:6', 'reject_qty' => 'decimal:6', 'rework_qty' => 'decimal:6', 'voided_at' => 'immutable_datetime'];
    }
}
