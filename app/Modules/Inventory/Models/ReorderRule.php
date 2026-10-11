<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $item_id
 * @property int|null $warehouse_id
 * @property string $min_qty
 * @property string|null $reorder_qty
 * @property bool $is_active
 */
#[Fillable(['item_id', 'warehouse_id', 'min_qty', 'reorder_qty', 'is_active'])]
class ReorderRule extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['min_qty' => 'decimal:6', 'reorder_qty' => 'decimal:6', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
