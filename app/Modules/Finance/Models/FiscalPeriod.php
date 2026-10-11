<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $fiscal_year_id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status open|closed|locked
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 */
#[Fillable(['fiscal_year_id', 'name', 'starts_on', 'ends_on', 'status', 'closed_at', 'closed_by'])]
class FiscalPeriod extends Model
{
    use Auditable;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const LOCKED = 'locked';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function year(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
