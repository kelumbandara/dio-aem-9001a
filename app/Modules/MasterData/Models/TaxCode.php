<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $kind vat|sscl|nbt|stamp|withholding|exempt|other
 * @property string|null $notes
 * @property bool $is_active
 */
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'kind', 'notes', 'is_active'])]
class TaxCode extends Model
{
    use Auditable, HasPublicId;

    public const KINDS = ['vat', 'sscl', 'nbt', 'stamp', 'withholding', 'exempt', 'other'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<TaxRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->orderByDesc('effective_from');
    }
}
