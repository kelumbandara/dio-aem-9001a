<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of the stock ledger. Immutable: the database refuses updates and deletes, and so does the model.
 *
 * @property int $id
 * @property Carbon $posting_date
 * @property int $document_id
 * @property int $line_id
 * @property int $item_id
 * @property int $location_id
 * @property int|null $lot_id
 * @property string $qty signed, base units
 * @property string $unit_cost
 * @property string $value signed, LKR
 * @property int|null $reverses_id
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Table(name: 'stock_ledger', timestamps: false)]
#[Fillable(['posting_date', 'document_id', 'line_id', 'item_id', 'location_id', 'lot_id', 'qty', 'unit_cost', 'value', 'reverses_id', 'created_by'])]
class StockLedgerEntry extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The stock ledger is append-only.'));
        static::deleting(fn () => throw new LogicException('The stock ledger is append-only.'));
    }

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'qty' => 'decimal:6', 'unit_cost' => 'decimal:6', 'value' => 'decimal:4', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<StockDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'document_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
