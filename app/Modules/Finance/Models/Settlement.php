<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A receipt from a customer (side receivable) or a payment to a supplier (side payable).
 *
 * @property int $id
 * @property string $public_id
 * @property string $side
 * @property string $document_no
 * @property int $party_id
 * @property int $bank_account_id
 * @property Carbon $settled_on
 * @property string $amount
 * @property string $method
 * @property string|null $reference
 * @property string|null $notes
 * @property string $status posted|reversed
 * @property int $journal_id
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['public_id', 'side', 'document_no', 'party_id', 'bank_account_id', 'settled_on', 'amount', 'method', 'reference', 'notes', 'status', 'journal_id', 'created_by'])]
class Settlement extends Model
{
    use HasPublicId;

    public const METHODS = ['cash', 'cheque', 'bank_transfer', 'card', 'other'];

    protected function casts(): array
    {
        return ['settled_on' => 'date', 'amount' => 'decimal:4'];
    }

    /** @return HasMany<SettlementAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SettlementAllocation::class);
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
