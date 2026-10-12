<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int|null $token_id
 * @property string $push_token
 * @property string $platform
 * @property string|null $device_name
 * @property string|null $app_version
 * @property string|null $locale
 * @property CarbonImmutable|null $last_seen_at
 */
#[RouteKey('public_id')]
#[Fillable(['user_id', 'token_id', 'push_token', 'platform', 'device_name', 'app_version', 'locale', 'last_seen_at'])]
class MobileDevice extends Model
{
    use HasPublicId;

    protected $table = 'mobile_devices';

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
