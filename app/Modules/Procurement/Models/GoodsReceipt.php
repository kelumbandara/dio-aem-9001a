<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $document_no
 * @property int $order_id
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property CarbonImmutable $receipt_date
 * @property string $status posted|reversed
 * @property string|null $supplier_dn_ref
 * @property string|null $notes
 * @property int|null $stock_document_id
 * @property int|null $reversal_document_id
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'order_id', 'supplier_id', 'warehouse_id', 'receipt_date', 'status', 'supplier_dn_ref', 'notes', 'stock_document_id', 'reversal_document_id', 'created_by'])]
class GoodsReceipt extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'goods_receipts';

    protected function casts(): array
    {
        return ['receipt_date' => 'immutable_date'];
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'receipt_id')->orderBy('id');
    }
}
