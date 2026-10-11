<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $journal_no
 * @property Carbon $entry_date
 * @property int $period_id
 * @property string $status posted|reversed
 * @property string $kind manual|system|reversal|opening
 * @property string $description
 * @property string|null $reference
 * @property string|null $source_type
 * @property string|null $source_id
 * @property int|null $reverses_id
 * @property int|null $reversed_by_id
 * @property string $total
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[RouteKey('public_id')]
#[Fillable(['journal_no', 'entry_date', 'period_id', 'status', 'kind', 'description', 'reference', 'source_type', 'source_id', 'reverses_id', 'reversed_by_id', 'total', 'created_by'])]
class JournalEntry extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'total' => 'decimal:4'];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id')->orderBy('line_no');
    }
}
