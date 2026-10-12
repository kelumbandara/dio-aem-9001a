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
 * A posted supplier bill. Immutable (the database refuses changes).
 *
 * @property int $id
 * @property bool $prices_include_vat
 * @property string $public_id
 * @property string $document_no our number
 * @property string $supplier_invoice_no the supplier's own number
 * @property int $order_id
 * @property int $supplier_id
 * @property CarbonImmutable $bill_date
 * @property CarbonImmutable $due_date
 * @property string|null $notes
 * @property string $net_total
 * @property string $vat_total
 * @property string $gross_total
 * @property string $variance_total + dearer than goods received, - cheaper
 * @property string|null $override_reason
 * @property int|null $override_by
 * @property int $journal_id
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'supplier_invoice_no', 'order_id', 'supplier_id', 'bill_date', 'due_date', 'notes', 'net_total', 'vat_total', 'gross_total', 'variance_total', 'override_reason', 'override_by', 'journal_id', 'created_by', 'prices_include_vat'])]
class SupplierBill extends Model
{
    use Auditable, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'supplier_bills';

    protected function casts(): array
    {
        return ['prices_include_vat' => 'boolean', 'bill_date' => 'immutable_date', 'due_date' => 'immutable_date', 'variance_total' => 'decimal:4', 'net_total' => 'decimal:4', 'vat_total' => 'decimal:4', 'gross_total' => 'decimal:4'];
    }

    /** @return HasMany<SupplierBillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class, 'bill_id')->orderBy('line_no');
    }
}
