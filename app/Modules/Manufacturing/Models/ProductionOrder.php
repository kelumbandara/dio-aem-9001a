<?php

namespace App\Modules\Manufacturing\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $order_no
 * @property int $item_id
 * @property int $bom_version_id
 * @property string $status
 * @property string $planned_qty
 * @property string $reported_qty
 * @property string $accepted_qty
 * @property string $rejected_qty
 * @property string $rework_qty
 * @property string $received_qty
 * @property CarbonImmutable $planned_start
 * @property CarbonImmutable $planned_end
 * @property int|null $production_line_id
 * @property int $source_warehouse_id
 * @property int $output_warehouse_id
 * @property string|null $notes
 * @property CarbonImmutable|null $released_at
 * @property int|null $released_by
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by
 * @property string $fg_value
 * @property string|null $variance_value
 * @property int $version
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['order_no', 'item_id', 'bom_version_id', 'status', 'planned_qty', 'reported_qty', 'accepted_qty', 'rejected_qty', 'rework_qty', 'received_qty', 'planned_start', 'planned_end', 'production_line_id', 'source_warehouse_id', 'output_warehouse_id', 'notes', 'released_at', 'released_by', 'cancel_reason', 'completed_at', 'completed_by', 'fg_value', 'variance_value', 'version', 'created_by'])]
class ProductionOrder extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'production_orders';

    public const STATUSES = ['draft', 'released', 'in_progress', 'completed', 'closed', 'cancelled'];

    /** Reported units nobody has judged yet (not accepted, rejected or sent to rework). */
    public function pendingQty(): string
    {
        return Decimal::sub(Decimal::sub(Decimal::sub((string) $this->reported_qty, (string) $this->accepted_qty), (string) $this->rejected_qty), (string) $this->rework_qty);
    }

    /** @return HasMany<ProductionReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(ProductionReport::class, 'order_id')->orderBy('id');
    }

    /** @return HasMany<QualityInspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(QualityInspection::class, 'order_id')->orderBy('id');
    }

    /** @return HasMany<FinishedGoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(FinishedGoodsReceipt::class, 'order_id')->orderBy('id');
    }

    /** @return HasMany<DowntimeEvent, $this> */
    public function downtime(): HasMany
    {
        return $this->hasMany(DowntimeEvent::class, 'order_id')->orderBy('id');
    }

    /** @return HasMany<ProductionOrderMaterial, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterial::class, 'order_id')->orderBy('line_no');
    }

    /** @return HasMany<ProductionOrderOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(ProductionOrderOperation::class, 'order_id')->orderBy('op_no');
    }

    /** @return HasMany<ProductionMaterialMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(ProductionMaterialMovement::class, 'order_id')->orderBy('id');
    }

    /** @return BelongsTo<BomVersion, $this> */
    public function bomVersion(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class);
    }

    protected function casts(): array
    {
        return ['planned_qty' => 'decimal:6', 'reported_qty' => 'decimal:6', 'accepted_qty' => 'decimal:6', 'rejected_qty' => 'decimal:6', 'rework_qty' => 'decimal:6', 'received_qty' => 'decimal:6', 'planned_start' => 'immutable_date', 'planned_end' => 'immutable_date', 'released_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'fg_value' => 'decimal:4', 'variance_value' => 'decimal:4', 'version' => 'integer'];
    }
}
