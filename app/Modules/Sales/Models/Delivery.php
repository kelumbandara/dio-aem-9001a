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
 * @property string $public_id
 * @property string $document_no
 * @property int $order_id
 * @property int $customer_id
 * @property int $warehouse_id
 * @property CarbonImmutable $delivery_date
 * @property string $status posted|reversed
 * @property string|null $notes
 * @property int|null $stock_document_id
 * @property int|null $reversal_document_id
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'order_id', 'customer_id', 'warehouse_id', 'delivery_date', 'status', 'notes', 'stock_document_id', 'reversal_document_id', 'created_by'])]
class Delivery extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'sales_deliveries';

    protected function casts(): array
    {
        return ['delivery_date' => 'immutable_date'];
    }

    /** @return HasMany<DeliveryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'delivery_id')->orderBy('id');
    }
}
