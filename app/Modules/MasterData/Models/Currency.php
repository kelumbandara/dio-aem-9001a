<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code ISO 4217
 * @property string $name
 * @property string|null $symbol
 * @property int $decimals
 * @property bool $is_base
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'symbol', 'decimals', 'is_base', 'is_active'])]
class Currency extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['decimals' => 'integer', 'is_base' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function base(): ?self
    {
        return static::query()->where('is_base', true)->first();
    }

    /** @return HasMany<ExchangeRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class)->orderByDesc('effective_on');
    }
}
