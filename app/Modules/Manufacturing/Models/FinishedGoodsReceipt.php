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
 * @property string $qty
 * @property string $unit_cost
 * @property string $value
 * @property CarbonImmutable $received_on
 * @property string|null $lot_no
 * @property int $stock_document_id
 * @property string $stock_document_no
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['order_id', 'qty', 'unit_cost', 'value', 'received_on', 'lot_no', 'stock_document_id', 'stock_document_no', 'created_by'])]
class FinishedGoodsReceipt extends Model
{
    use HasPublicId;

    protected $table = 'finished_goods_receipts';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'unit_cost' => 'decimal:6', 'value' => 'decimal:4', 'received_on' => 'immutable_date'];
    }
}
