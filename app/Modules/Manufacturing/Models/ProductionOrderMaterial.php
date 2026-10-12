<?php

namespace App\Modules\Manufacturing\Models;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int $line_no
 * @property int $item_id
 * @property string $required_qty
 * @property string $issued_qty
 * @property string $returned_qty
 * @property string $issued_value
 * @property string $returned_value
 */
#[Fillable(['order_id', 'line_no', 'item_id', 'required_qty', 'issued_qty', 'returned_qty', 'issued_value', 'returned_value'])]
class ProductionOrderMaterial extends Model
{
    protected $table = 'production_order_materials';

    public $timestamps = false;

    /** Net quantity now with the order (issued less returned). */
    public function netQty(): string
    {
        return Decimal::sub((string) $this->issued_qty, (string) $this->returned_qty);
    }

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'required_qty' => 'decimal:6', 'issued_qty' => 'decimal:6', 'returned_qty' => 'decimal:6', 'issued_value' => 'decimal:4', 'returned_value' => 'decimal:4'];
    }
}
