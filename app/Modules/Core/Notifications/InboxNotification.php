<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\DTOs\NotificationMessage;
use Illuminate\Notifications\Notification;

/**
 * In-app inbox entry. Stores translation keys (not text) so it renders in the reader's language.
 * Written synchronously, inside the caller's transaction: a rolled-back change leaves no notification.
 * External channels (email, WhatsApp, SMS) are added in Communications and will be queued.
 */
final class InboxNotification extends Notification
{
    public function __construct(public readonly NotificationMessage $message) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'key' => $this->message->key,
            'params' => $this->message->params,
            'url' => $this->message->url,
            'level' => $this->message->level,
            'subject_type' => $this->message->subjectType,
            'subject_id' => $this->message->subjectId,
        ];
    }
}
