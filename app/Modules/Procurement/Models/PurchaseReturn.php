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
 * @property string $document_no also the debit note number
 * @property int $order_id
 * @property int $supplier_id
 * @property int|null $bill_id
 * @property int $warehouse_id
 * @property CarbonImmutable $return_date
 * @property string $reason
 * @property string $net_total
 * @property string $vat_total
 * @property string $gross_total
 * @property int|null $stock_document_id
 * @property int|null $journal_id
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'order_id', 'supplier_id', 'bill_id', 'warehouse_id', 'return_date', 'reason', 'net_total', 'vat_total', 'gross_total', 'stock_document_id', 'journal_id', 'created_by'])]
class PurchaseReturn extends Model
{
    use Auditable, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'purchase_returns';

    protected function casts(): array
    {
        return ['return_date' => 'immutable_date', 'net_total' => 'decimal:4', 'vat_total' => 'decimal:4', 'gross_total' => 'decimal:4'];
    }

    /** @return HasMany<PurchaseReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class, 'return_id')->orderBy('id');
    }
}
