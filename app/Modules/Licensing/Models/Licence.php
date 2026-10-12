<?php

namespace App\Modules\Licensing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $serial
 * @property string $token
 * @property string $installation_id
 * @property string $company
 * @property string $plan
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $valid_until
 * @property CarbonImmutable $issued_at
 * @property int|null $max_users
 * @property list<string>|null $modules
 * @property int|null $entered_by
 */
#[Fillable(['serial', 'token', 'installation_id', 'company', 'plan', 'valid_from', 'valid_until', 'issued_at', 'max_users', 'modules', 'entered_by'])]
class Licence extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'licences';

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_until' => 'immutable_date', 'issued_at' => 'immutable_datetime', 'modules' => 'array', 'max_users' => 'integer'];
    }
}
