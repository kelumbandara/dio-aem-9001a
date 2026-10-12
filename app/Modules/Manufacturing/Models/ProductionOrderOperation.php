<?php

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $op_no
 * @property string $name
 * @property int $work_center_id
 * @property int|null $machine_id
 * @property string $planned_setup_minutes
 * @property string $planned_run_minutes
 * @property string $status
 */
#[Fillable(['order_id', 'op_no', 'name', 'work_center_id', 'machine_id', 'planned_setup_minutes', 'planned_run_minutes', 'status'])]
class ProductionOrderOperation extends Model
{
    protected $table = 'production_order_operations';

    public $timestamps = false;

    /** @return BelongsTo<WorkCenter, $this> */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    protected function casts(): array
    {
        return ['op_no' => 'integer', 'planned_setup_minutes' => 'decimal:2', 'planned_run_minutes' => 'decimal:4'];
    }
}
