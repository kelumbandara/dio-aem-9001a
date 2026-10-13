<?php

namespace App\Modules\Communications\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A phone number or e-mail address proven to belong to a user.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $channel
 * @property string $address
 * @property CarbonImmutable $verified_at
 */
#[RouteKey('public_id')]
#[Fillable(['user_id', 'channel', 'address', 'verified_at'])]
class Identity extends Model
{
    use HasPublicId;

    protected $table = 'comm_identities';

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
