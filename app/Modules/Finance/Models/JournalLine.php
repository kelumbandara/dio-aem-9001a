<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable: the database refuses updates and deletes, and so does the model.
 *
 * @property int $id
 * @property int $entry_id
 * @property int $line_no
 * @property int $account_id
 * @property string $debit
 * @property string $credit
 * @property string|null $description
 * @property string|null $party_type
 * @property int|null $party_id
 */
#[Table(name: 'journal_lines', timestamps: false)]
#[Fillable(['entry_id', 'line_no', 'account_id', 'debit', 'credit', 'description', 'party_type', 'party_id'])]
class JournalLine extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal lines are append-only.'));
        static::deleting(fn () => throw new LogicException('Journal lines are append-only.'));
    }

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }
}
