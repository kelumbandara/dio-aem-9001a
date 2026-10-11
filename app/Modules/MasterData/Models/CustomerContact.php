<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string|null $job_title
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 */
#[Fillable(['customer_id', 'name', 'job_title', 'email', 'phone', 'is_primary'])]
class CustomerContact extends Model
{
    // Changes are recorded in the audit trail of the owning Customer (SaveCustomer).

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
