<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $key
 * @property int $account_id
 */
#[Table(name: 'account_mappings')]
#[Fillable(['key', 'account_id'])]
class AccountMapping extends Model
{
    use Auditable;

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
