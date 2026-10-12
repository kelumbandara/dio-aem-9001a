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
 * @property string $category
 * @property bool $is_active
 */
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'category', 'is_active'])]
class DowntimeReason extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'downtime_reasons';

    public const CATEGORIES = ['planned', 'breakdown', 'changeover', 'material', 'quality', 'other'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
