<?php

namespace App\Modules\Manufacturing\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property CarbonImmutable $inspected_on
 * @property string $accepted_qty
 * @property string $rejected_qty
 * @property string $rework_qty
 * @property string|null $reject_reason
 * @property string|null $notes
 * @property int|null $inspected_by
 */
#[RouteKey('public_id')]
#[Fillable(['order_id', 'inspected_on', 'accepted_qty', 'rejected_qty', 'rework_qty', 'reject_reason', 'notes', 'inspected_by'])]
class QualityInspection extends Model
{
    use HasPublicId;

    protected $table = 'quality_inspections';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['inspected_on' => 'immutable_date', 'accepted_qty' => 'decimal:6', 'rejected_qty' => 'decimal:6', 'rework_qty' => 'decimal:6'];
    }
}
