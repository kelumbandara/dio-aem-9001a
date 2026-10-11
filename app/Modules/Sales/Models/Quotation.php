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
 * @property bool $prices_include_vat
 * @property string $public_id
 * @property string $document_no
 * @property int $customer_id
 * @property CarbonImmutable $quote_date
 * @property CarbonImmutable $valid_until
 * @property string $status draft|sent|accepted|rejected|converted|cancelled
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $terms
 * @property string $net_total
 * @property string $vat_total
 * @property string $levy_total
 * @property string $gross_total
 * @property int $version
 * @property int|null $created_by
 */
#[RouteKey('public_id')]
#[Fillable(['document_no', 'customer_id', 'quote_date', 'valid_until', 'status', 'reference', 'notes', 'terms', 'net_total', 'vat_total', 'levy_total', 'gross_total', 'version', 'created_by', 'prices_include_vat'])]
class Quotation extends Model
{
    use Auditable, HasPublicId;

    protected $table = 'sales_quotations';

    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'converted', 'cancelled'];

    protected function casts(): array
    {
        return [
            'prices_include_vat' => 'boolean',
            'quote_date' => 'immutable_date', 'valid_until' => 'immutable_date', 'version' => 'integer',
            'net_total' => 'decimal:4', 'vat_total' => 'decimal:4', 'levy_total' => 'decimal:4', 'gross_total' => 'decimal:4',
        ];
    }

    /** @return HasMany<QuotationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'document_id')->orderBy('line_no');
    }
}
