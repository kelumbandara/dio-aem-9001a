<?php

namespace App\Modules\Communications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A code sent to an address to prove it is the user's. Only a fingerprint of the code is kept.
 *
 * @property int $id
 * @property int $user_id
 * @property string $channel
 * @property string $address
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $consumed_at
 */
#[Fillable(['user_id', 'channel', 'address', 'code_hash', 'expires_at', 'attempts', 'consumed_at'])]
class IdentityChallenge extends Model
{
    protected $table = 'comm_identity_challenges';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
