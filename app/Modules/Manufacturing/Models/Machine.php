<?php

namespace App\Modules\Manufacturing\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property int $work_center_id
 * @property int|null $production_line_id
 * @property string $status
 * @property string|null $notes
 */
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'work_center_id', 'production_line_id', 'status', 'notes'])]
class Machine extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'machines';

    public const STATUSES = ['available', 'maintenance', 'retired'];

    /** @return BelongsTo<WorkCenter, $this> */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /** @return BelongsTo<ProductionLine, $this> */
    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class);
    }
}
