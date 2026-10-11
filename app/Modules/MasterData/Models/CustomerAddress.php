<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $type billing|shipping|other
 * @property string $line1
 * @property string|null $line2
 * @property string|null $city
 * @property string|null $district
 * @property string|null $postal_code
 * @property string $country
 * @property bool $is_default
 */
#[Fillable(['customer_id', 'type', 'line1', 'line2', 'city', 'district', 'postal_code', 'country', 'is_default'])]
class CustomerAddress extends Model
{
    // Changes are recorded in the audit trail of the owning Customer (SaveCustomer).

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
