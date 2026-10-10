<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\Notifier;
use App\Modules\Core\DTOs\NotificationMessage;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\InboxNotification;
use Illuminate\Support\Facades\Notification;

final class DatabaseNotifier implements Notifier
{
    public function notify(array $userIds, NotificationMessage $message): void
    {
        $users = User::query()
            ->whereIn('id', array_values(array_unique($userIds)))
            ->where('is_active', true)
            ->where('user_type', User::TYPE_INTERNAL)
            ->get();

        if ($users->isNotEmpty()) {
            Notification::sendNow($users, new InboxNotification($message));
        }
    }
}
