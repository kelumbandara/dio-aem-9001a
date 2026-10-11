<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An invoice (receivable) or bill (payable) that is waiting to be settled.
 *
 * @property int $id
 * @property string $public_id
 * @property string $side receivable|payable
 * @property int $party_id
 * @property string $document_no
 * @property Carbon $document_date
 * @property Carbon $due_date
 * @property string $amount
 * @property string $open_amount
 * @property string|null $source_type
 * @property string|null $source_id
 * @property int $journal_id
 */
#[Table(name: 'open_items')]
#[RouteKey('public_id')]
#[Fillable(['side', 'party_id', 'document_no', 'document_date', 'due_date', 'amount', 'open_amount', 'source_type', 'source_id', 'journal_id'])]
class OpenItem extends Model
{
    use HasPublicId;

    public const RECEIVABLE = 'receivable';

    public const PAYABLE = 'payable';

    protected function casts(): array
    {
        return ['document_date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:4', 'open_amount' => 'decimal:4'];
    }
}
