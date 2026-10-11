<?php

namespace App\Modules\Sales\Models;

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
 * @property int $customer_id
 * @property int|null $quotation_id
 * @property int $warehouse_id
 * @property CarbonImmutable $order_date
 * @property CarbonImmutable|null $required_date
 * @property string $status draft|confirmed|completed|cancelled
 * @property string|null $customer_ref
 * @property int $payment_terms_days
 * @property string|null $notes
 * @property string $net_total
 * @property string $vat_total
 * @property string $levy_total
 * @property string $gross_total
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by
 * @property string|null $credit_override_reason
 * @property int|null $credit_override_by
 * @property string|null $cancel_reason
 * @property int $version
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'customer_id', 'quotation_id', 'warehouse_id', 'order_date', 'required_date', 'status', 'customer_ref', 'payment_terms_days', 'notes', 'net_total', 'vat_total', 'levy_total', 'gross_total', 'confirmed_at', 'confirmed_by', 'credit_override_reason', 'credit_override_by', 'cancel_reason', 'version', 'created_by', 'prices_include_vat'])]
class Order extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'sales_orders';

    public const STATUSES = ['draft', 'confirmed', 'completed', 'cancelled'];

    protected function casts(): array
    {
        return [
            'prices_include_vat' => 'boolean',
            'order_date' => 'immutable_date', 'required_date' => 'immutable_date', 'confirmed_at' => 'immutable_datetime', 'version' => 'integer', 'payment_terms_days' => 'integer',
            'net_total' => 'decimal:4', 'vat_total' => 'decimal:4', 'levy_total' => 'decimal:4', 'gross_total' => 'decimal:4',
        ];
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'document_id')->orderBy('line_no');
    }
}
