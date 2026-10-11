<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $item_id
 * @property string $lot_no
 * @property Carbon|null $expiry_date
 * @property Carbon $received_on
 */
#[Fillable(['item_id', 'lot_no', 'expiry_date', 'received_on'])]
class Lot extends Model
{
    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'received_on' => 'date'];
    }
}
