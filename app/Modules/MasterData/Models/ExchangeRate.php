<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Units of the base currency per one unit of the foreign currency, from `effective_on`.
 *
 * @property int $id
 * @property int $currency_id
 * @property Carbon $effective_on
 * @property string $rate decimal string, 8 places
 * @property string|null $source
 */
#[Fillable(['currency_id', 'effective_on', 'rate', 'source', 'created_by'])]
class ExchangeRate extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'rate' => 'decimal:8'];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
