<?php

namespace App\Modules\Manufacturing\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $capacity_hours_per_day
 * @property string $hourly_rate
 * @property bool $is_active
 */
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'description', 'capacity_hours_per_day', 'hourly_rate', 'is_active'])]
class WorkCenter extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'work_centers';

    protected function casts(): array
    {
        return ['capacity_hours_per_day' => 'decimal:2', 'hourly_rate' => 'decimal:4', 'is_active' => 'boolean'];
    }
}
