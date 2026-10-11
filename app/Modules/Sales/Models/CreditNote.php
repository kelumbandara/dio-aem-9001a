<?php

namespace App\Modules\Sales\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $document_no
 * @property int $invoice_id
 * @property int $customer_id
 * @property CarbonImmutable $note_date
 * @property string $reason
 * @property bool $restock
 * @property int|null $warehouse_id
 * @property string $net_total
 * @property string $vat_total
 * @property string $levy_total
 * @property string $gross_total
 * @property int $journal_id
 * @property int|null $stock_document_id
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'invoice_id', 'customer_id', 'note_date', 'reason', 'restock', 'warehouse_id', 'net_total', 'vat_total', 'levy_total', 'gross_total', 'journal_id', 'stock_document_id', 'created_by'])]
class CreditNote extends Model
{
    use Auditable, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'sales_credit_notes';

    protected function casts(): array
    {
        return [
            'note_date' => 'immutable_date', 'restock' => 'boolean',
            'net_total' => 'decimal:4', 'vat_total' => 'decimal:4', 'levy_total' => 'decimal:4', 'gross_total' => 'decimal:4',
        ];
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class, 'credit_note_id')->orderBy('id');
    }

    /** @return HasMany<CreditNoteTax, $this> */
    public function taxes(): HasMany
    {
        return $this->hasMany(CreditNoteTax::class, 'credit_note_id')->orderBy('id');
    }
}
