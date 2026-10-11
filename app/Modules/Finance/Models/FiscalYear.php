<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
#[Fillable(['name', 'starts_on', 'ends_on'])]
class FiscalYear extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return HasMany<FiscalPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('starts_on');
    }
}
