<?php

namespace App\Modules\Communications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the consent history of an address on a channel. Lines are only ever added (the database refuses anything else).
 *
 * @property int $id
 * @property string $channel
 * @property string $address
 * @property string $status
 * @property string $source
 * @property string|null $note
 * @property string|null $party_type
 * @property int|null $party_id
 * @property int|null $recorded_by
 * @property CarbonImmutable $created_at
 */
#[Fillable(['channel', 'address', 'status', 'source', 'note', 'party_type', 'party_id', 'recorded_by'])]
class Consent extends Model
{
    protected $table = 'comm_consents';

    public const UPDATED_AT = null;
}
