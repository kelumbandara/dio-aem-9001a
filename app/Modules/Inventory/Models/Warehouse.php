<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property int|null $branch_id
 * @property bool $uses_locations
 * @property string|null $notes
 * @property bool $is_active
 * @property int $version
 */
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'branch_id', 'uses_locations', 'notes', 'is_active', 'version'])]
class Warehouse extends Model
{
    use Auditable, HasPublicId;

    protected function casts(): array
    {
        return ['uses_locations' => 'boolean', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class)->orderByDesc('is_default')->orderBy('code');
    }

    /** @return HasOne<Location, $this> */
    public function defaultLocation(): HasOne
    {
        return $this->hasOne(Location::class)->where('is_default', true);
    }
}
