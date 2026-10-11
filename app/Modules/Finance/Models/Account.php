<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $class asset|liability|equity|income|expense
 * @property int|null $parent_id
 * @property bool $is_group
 * @property string|null $subtype
 * @property bool $is_active
 * @property string|null $notes
 * @property int $version
 */
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'class', 'parent_id', 'is_group', 'subtype', 'is_active', 'notes', 'version'])]
class Account extends Model
{
    use Auditable, HasPublicId;

    public const CLASSES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Classes whose natural balance is a credit. */
    public const CREDIT_CLASSES = ['liability', 'equity', 'income'];

    protected function casts(): array
    {
        return ['is_group' => 'boolean', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
