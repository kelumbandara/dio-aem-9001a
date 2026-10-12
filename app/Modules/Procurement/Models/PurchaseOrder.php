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
 * @property bool $prices_include_vat
 * @property string $public_id
 * @property string $document_no
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property CarbonImmutable $order_date
 * @property CarbonImmutable|null $expected_date
 * @property string $status draft|pending_approval|approved|completed|cancelled
 * @property string|null $supplier_ref
 * @property int $payment_terms_days
 * @property string|null $notes
 * @property string $net_total
 * @property string $vat_total
 * @property string $gross_total
 * @property string|null $approval_request_id
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property string|null $cancel_reason
 * @property int $version
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'supplier_id', 'warehouse_id', 'order_date', 'expected_date', 'status', 'supplier_ref', 'payment_terms_days', 'notes', 'net_total', 'vat_total', 'gross_total', 'approval_request_id', 'approved_at', 'approved_by', 'cancel_reason', 'version', 'created_by', 'prices_include_vat'])]
class PurchaseOrder extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'purchase_orders';

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'completed', 'cancelled'];

    protected function casts(): array
    {
        return ['prices_include_vat' => 'boolean', 'order_date' => 'immutable_date', 'expected_date' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'version' => 'integer', 'payment_terms_days' => 'integer', 'net_total' => 'decimal:4', 'vat_total' => 'decimal:4', 'gross_total' => 'decimal:4'];
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'document_id')->orderBy('line_no');
    }
}
