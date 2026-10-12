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
 * @property string $start_time
 * @property string $end_time
 * @property string $days
 * @property bool $is_active
 */
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'start_time', 'end_time', 'days', 'is_active'])]
class Shift extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'shifts';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
