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
 * A posted tax invoice. Immutable (the database refuses changes): corrections are credit notes.
 *
 * @property int $id
 * @property bool $prices_include_vat
 * @property string $public_id
 * @property string $document_no
 * @property int $order_id
 * @property int $customer_id
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable $due_date
 * @property string|null $customer_ref
 * @property string|null $notes
 * @property string $net_total
 * @property string $vat_total
 * @property string $levy_total
 * @property string $gross_total
 * @property int $journal_id
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'order_id', 'customer_id', 'invoice_date', 'due_date', 'customer_ref', 'notes', 'net_total', 'vat_total', 'levy_total', 'gross_total', 'journal_id', 'created_by', 'prices_include_vat'])]
class Invoice extends Model
{
    use Auditable, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'sales_invoices';

    protected function casts(): array
    {
        return [
            'prices_include_vat' => 'boolean',
            'invoice_date' => 'immutable_date', 'due_date' => 'immutable_date',
            'net_total' => 'decimal:4', 'vat_total' => 'decimal:4', 'levy_total' => 'decimal:4', 'gross_total' => 'decimal:4',
        ];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id')->orderBy('line_no');
    }

    /** @return HasMany<InvoiceTax, $this> */
    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class, 'invoice_id')->orderBy('id');
    }
}
