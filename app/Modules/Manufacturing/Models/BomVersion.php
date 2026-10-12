<?php

namespace App\Modules\Manufacturing\Models;

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
 * @property int $item_id
 * @property int $version_no
 * @property string $status
 * @property string $base_qty
 * @property string|null $description
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property CarbonImmutable|null $activated_at
 * @property int|null $activated_by
 * @property int $version
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['item_id', 'version_no', 'status', 'base_qty', 'description', 'effective_from', 'effective_to', 'activated_at', 'activated_by', 'version', 'created_by'])]
class BomVersion extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'bom_versions';

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    /** @return HasMany<BomComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(BomComponent::class)->orderBy('line_no');
    }

    /** @return HasMany<BomOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(BomOperation::class)->orderBy('op_no');
    }

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'base_qty' => 'decimal:6', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'activated_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
