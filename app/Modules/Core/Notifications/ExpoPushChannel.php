<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Jobs\SendPush;
use App\Modules\Core\Models\MobileDevice;
use Illuminate\Database\Eloquent\Model;

/** Notification channel: queue a push for a person who has a phone registered; costs nothing for everyone else. */
final class ExpoPushChannel
{
    public function send(object $notifiable, InboxNotification $notification): void
    {
        if (! (bool) config('mobile.push.enabled') || ! $notifiable instanceof Model) {
            return;
        }
        if (! MobileDevice::query()->where('user_id', $notifiable->getKey())->exists()) {
            return;
        }

        $m = $notification->message;
        SendPush::dispatch((int) $notifiable->getKey(), $m->key, $m->params, $m->url, $m->level, $m->subjectType, $m->subjectId);
    }
}
