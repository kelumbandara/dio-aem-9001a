<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $warehouse_id
 * @property string $code
 * @property string $name
 * @property bool $is_default
 * @property bool $is_active
 */
#[Fillable(['warehouse_id', 'code', 'name', 'is_default', 'is_active'])]
class Location extends Model
{
    // Changes are audited through the owning Warehouse's explicit audit entries.

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
