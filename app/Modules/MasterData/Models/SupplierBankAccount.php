<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Account numbers are encrypted at rest, masked on screen unless the viewer holds
 * `masterdata.supplier.bank.view`, and never written to the audit log (see core.audit.redact).
 *
 * @property int $id
 * @property int $supplier_id
 * @property string $bank_name
 * @property string|null $branch_name
 * @property string $account_name
 * @property string $account_no
 * @property int|null $currency_id
 * @property bool $is_default
 * @property bool $is_active
 */
#[Fillable(['supplier_id', 'bank_name', 'branch_name', 'account_name', 'account_no', 'currency_id', 'is_default', 'is_active'])]
#[Hidden(['account_no'])]
class SupplierBankAccount extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['account_no' => 'encrypted', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** "••••5678" – enough to recognise an account without exposing it. */
    public function maskedAccountNo(): string
    {
        $digits = $this->account_no;

        return '••••'.mb_substr($digits, -4);
    }
}
