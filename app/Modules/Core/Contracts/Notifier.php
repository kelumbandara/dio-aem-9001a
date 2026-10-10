<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\DTOs\NotificationMessage;

interface Notifier
{
    /**
     * Notify users in the app (inbox). Duplicates are removed and inactive users are skipped.
     * Delivery is queued after the surrounding transaction commits.
     *
     * @param  list<int>  $userIds
     */
    public function notify(array $userIds, NotificationMessage $message): void;
}
