<?php

namespace App\Modules\Communications\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property string $channel
 * @property string $locale
 * @property string $purpose
 * @property string|null $subject
 * @property string $body
 * @property string|null $provider_template
 * @property string|null $provider_language
 * @property list<string>|null $param_order
 * @property bool $sensitive
 * @property bool $approved
 * @property bool $is_active
 */
#[RouteKey('public_id')]
#[Fillable(['key', 'channel', 'locale', 'purpose', 'subject', 'body', 'provider_template', 'provider_language', 'param_order', 'sensitive', 'approved', 'is_active'])]
class Template extends Model
{
    use HasPublicId;

    protected $table = 'comm_templates';

    protected function casts(): array
    {
        return ['param_order' => 'array', 'sensitive' => 'boolean', 'approved' => 'boolean', 'is_active' => 'boolean'];
    }
}
