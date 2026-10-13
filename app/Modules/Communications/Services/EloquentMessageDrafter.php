<?php

namespace App\Modules\Communications\Services;

use App\Modules\Communications\Contracts\MessageDrafter;
use App\Modules\Communications\Contracts\Messenger;
use App\Modules\Communications\Exceptions\CommunicationsException;
use Illuminate\Contracts\Auth\Access\Authorizable;

final class EloquentMessageDrafter implements MessageDrafter, Messenger
{
    public function __construct(private readonly MessageService $messages) {}

    public function draft(Authorizable $actor, int $userId, array $data): array
    {
        if (! $actor->can('comm.message.draft')) {
            throw CommunicationsException::make('comm.forbidden', 'You may not prepare messages.', 403);
        }
        // only the named fields are taken: nothing else the helper sends can reach the message
        $m = $this->messages->draft([
            'party_type' => $data['party_type'] ?? null, 'party' => $data['party'] ?? null, 'channel' => $data['channel'] ?? null,
            'template' => $data['template'] ?? null, 'params' => (array) ($data['params'] ?? []), 'locale' => $data['locale'] ?? null,
        ], $userId, true);

        return ['id' => $m->public_id, 'channel' => $m->channel, 'to' => Address::mask($m->channel, $m->address), 'template' => (string) $m->template_key, 'status' => $m->status, 'subject' => $m->subject];
    }

    public function send(array $data, int $by): string
    {
        $m = $this->messages->draft($data, $by);
        $this->messages->send($m, $by, 'web');

        return $m->public_id;
    }
}
