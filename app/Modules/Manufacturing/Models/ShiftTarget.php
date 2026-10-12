<?php

namespace App\Modules\Manufacturing\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $shift_id
 * @property int $item_id
 * @property string $target_qty
 * @property bool $is_active
 */
#[Fillable(['shift_id', 'item_id', 'target_qty', 'is_active'])]
class ShiftTarget extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'shift_targets';

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    protected function casts(): array
    {
        return ['target_qty' => 'decimal:6', 'is_active' => 'boolean'];
    }
}
