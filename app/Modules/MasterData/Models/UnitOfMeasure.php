<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property int $decimals decimal places allowed in quantities of this unit
 * @property bool $is_active
 */
#[Table(name: 'units_of_measure')]
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'decimals', 'is_active'])]
class UnitOfMeasure extends Model
{
    use Auditable, HasPublicId;

    protected function casts(): array
    {
        return ['decimals' => 'integer', 'is_active' => 'boolean'];
    }
}
