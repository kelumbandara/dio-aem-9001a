<?php

namespace App\Modules\Manufacturing\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $material_id
 * @property string $kind
 * @property string $qty
 * @property string $value
 * @property int $stock_document_id
 * @property string $stock_document_no
 * @property CarbonImmutable $posted_on
 * @property string|null $reason
 * @property bool $over_issue
 * @property int|null $created_by
 */
#[Fillable(['order_id', 'material_id', 'kind', 'qty', 'value', 'stock_document_id', 'stock_document_no', 'posted_on', 'reason', 'over_issue', 'created_by'])]
class ProductionMaterialMovement extends Model
{
    use HasPublicId;

    protected $table = 'production_material_movements';

    public const UPDATED_AT = null;

    /** @return BelongsTo<ProductionOrderMaterial, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderMaterial::class, 'material_id');
    }

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'value' => 'decimal:4', 'posted_on' => 'immutable_date', 'over_issue' => 'boolean'];
    }
}
