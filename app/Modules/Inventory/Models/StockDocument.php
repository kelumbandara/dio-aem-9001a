<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $document_no
 * @property string $doc_type receipt|issue|transfer|adjustment
 * @property string $status posted|reversed
 * @property Carbon $posting_date
 * @property string $reason
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $source_type
 * @property string|null $source_id
 * @property int|null $reverses_id
 * @property int|null $reversed_by_id
 * @property string $total_value
 * @property string|null $approval_request_id
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'doc_type', 'status', 'posting_date', 'reason', 'reference', 'notes', 'source_type', 'source_id', 'reverses_id', 'reversed_by_id', 'total_value', 'approval_request_id', 'created_by'])]
class StockDocument extends Model
{
    use HasPublicId;

    public const RECEIPT = 'receipt';

    public const ISSUE = 'issue';

    public const TRANSFER = 'transfer';

    public const ADJUSTMENT = 'adjustment';

    public const TYPES = [self::RECEIPT, self::ISSUE, self::TRANSFER, self::ADJUSTMENT];

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'total_value' => 'decimal:4'];
    }

    /** @return HasMany<StockDocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockDocumentLine::class, 'document_id')->orderBy('line_no');
    }
}
