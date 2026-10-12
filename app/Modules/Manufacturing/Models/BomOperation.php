<?php

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bom_version_id
 * @property int $op_no
 * @property string $name
 * @property int $work_center_id
 * @property int|null $machine_id
 * @property string $setup_minutes
 * @property string $run_minutes
 * @property string|null $notes
 */
#[Fillable(['bom_version_id', 'op_no', 'name', 'work_center_id', 'machine_id', 'setup_minutes', 'run_minutes', 'notes'])]
class BomOperation extends Model
{
    protected $table = 'bom_operations';

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
        return ['op_no' => 'integer', 'setup_minutes' => 'decimal:2', 'run_minutes' => 'decimal:4'];
    }
}
