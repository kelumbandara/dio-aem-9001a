<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $supplier_id
 * @property string $name
 * @property string|null $job_title
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 */
#[Fillable(['supplier_id', 'name', 'job_title', 'email', 'phone', 'is_primary'])]
class SupplierContact extends Model
{
    // Changes are recorded in the audit trail of the owning Supplier (SaveSupplier).

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
