<?php

namespace App\Modules\Communications\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $direction
 * @property string $channel
 * @property string $status
 * @property string|null $party_type
 * @property int|null $party_id
 * @property string $address
 * @property string|null $template_key
 * @property string $locale
 * @property string|null $subject
 * @property string $body
 * @property array<string, string>|null $params
 * @property string|null $provider_message_id
 * @property string|null $error_code
 * @property string|null $error
 * @property int $attempts
 * @property int|null $requested_by
 * @property bool $drafted_by_ai
 * @property string|null $approval_request_id
 * @property string|null $related_type
 * @property string|null $related_id
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable $created_at
 */
#[RouteKey('public_id')]
#[Fillable(['direction', 'channel', 'status', 'party_type', 'party_id', 'address', 'template_key', 'locale', 'subject', 'body', 'params', 'provider_message_id', 'error_code', 'error', 'attempts',
    'requested_by', 'drafted_by_ai', 'approval_request_id', 'related_type', 'related_id', 'queued_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at'])]
class Message extends Model
{
    use HasPublicId;

    protected $table = 'comm_messages';

    public const STATUSES = ['draft', 'awaiting_approval', 'queued', 'sending', 'sent', 'delivered', 'read', 'failed', 'cancelled', 'received'];

    protected function casts(): array
    {
        return [
            'params' => 'array', 'drafted_by_ai' => 'boolean', 'queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }
}
