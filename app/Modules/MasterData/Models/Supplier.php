<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string $kind company|individual
 * @property string|null $tax_id
 * @property string|null $business_reg_no
 * @property string|null $nic
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property int|null $currency_id
 * @property int|null $payment_terms_id
 * @property int|null $price_list_id
 * @property int|null $lead_time_days
 * @property string|null $notes
 * @property bool $is_active
 * @property int $version
 */
#[RouteKey('public_id')]
#[Fillable([
    'code', 'name', 'legal_name', 'kind', 'tax_id', 'business_reg_no', 'nic', 'email', 'phone', 'website',
    'currency_id', 'payment_terms_id', 'price_list_id', 'lead_time_days', 'notes', 'is_active', 'version',
])]
class Supplier extends Model
{
    use Auditable, HasPublicId;

    protected function casts(): array
    {
        return ['nic' => 'encrypted', 'lead_time_days' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    /** @return HasMany<SupplierContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /** @return HasMany<SupplierAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(SupplierAddress::class)->orderByDesc('is_default')->orderBy('id');
    }

    /** @return HasMany<SupplierBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SupplierBankAccount::class)->orderByDesc('is_default')->orderBy('id');
    }

    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerms(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_terms_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
