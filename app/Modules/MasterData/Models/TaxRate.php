<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tax_code_id
 * @property string $rate percent, e.g. "18.0000"
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
#[Fillable(['tax_code_id', 'rate', 'effective_from', 'effective_to'])]
class TaxRate extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    /** @return BelongsTo<TaxCode, $this> */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
