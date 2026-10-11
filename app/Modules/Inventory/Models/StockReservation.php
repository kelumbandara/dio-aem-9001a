<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $item_id
 * @property int $warehouse_id
 * @property string $qty
 * @property string $consumed_qty
 * @property string $status active|released|consumed
 * @property string $source_type
 * @property string $source_id
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['item_id', 'warehouse_id', 'qty', 'consumed_qty', 'status', 'source_type', 'source_id', 'created_by'])]
class StockReservation extends Model
{
    use HasPublicId;

    public const ACTIVE = 'active';

    public const RELEASED = 'released';

    public const CONSUMED = 'consumed';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'consumed_qty' => 'decimal:6'];
    }
}
