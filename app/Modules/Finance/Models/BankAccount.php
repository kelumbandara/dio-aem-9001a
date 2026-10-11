<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $account_id
 * @property string $name
 * @property string $kind bank|cash
 * @property string|null $bank_name
 * @property string|null $account_no
 * @property string $currency
 * @property bool $is_active
 */
#[RouteKey('public_id')]
#[Hidden(['account_no'])]
#[Fillable(['account_id', 'name', 'kind', 'bank_name', 'account_no', 'currency', 'is_active'])]
class BankAccount extends Model
{
    use Auditable, HasPublicId;

    protected function casts(): array
    {
        return ['account_no' => 'encrypted', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function maskedAccountNo(): ?string
    {
        return $this->account_no === null ? null : '••••'.substr($this->account_no, -4);
    }
}
