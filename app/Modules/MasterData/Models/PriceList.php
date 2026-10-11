<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $type sales|purchase
 * @property int $currency_id
 * @property bool $tax_inclusive
 * @property bool $is_default
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 * @property int $version
 */
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'type', 'currency_id', 'tax_inclusive', 'is_default', 'valid_from', 'valid_to', 'is_active', 'version'])]
class PriceList extends Model
{
    use Auditable, HasPublicId;

    public const SALES = 'sales';

    public const PURCHASE = 'purchase';

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean',
            'valid_from' => 'date', 'valid_to' => 'date', 'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PriceListItem::class)->orderBy('item_id')->orderBy('uom_id')->orderBy('min_qty');
    }
}
