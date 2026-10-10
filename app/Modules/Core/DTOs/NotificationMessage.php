<?php

namespace App\Modules\Core\DTOs;

/**
 * A notification described by translation keys, not text, so each recipient sees it in their own
 * language when they read it. `{key}.title` and `{key}.body` must exist in the module's lang files.
 */
final readonly class NotificationMessage
{
    /**
     * @param  string  $key  e.g. "approvals::notifications.requested"
     * @param  array<string, scalar|null>  $params  placeholders for the translation
     * @param  'info'|'success'|'warning'|'danger'  $level
     */
    public function __construct(
        public string $key,
        public array $params = [],
        public ?string $url = null,
        public string $level = 'info',
        public ?string $subjectType = null,
        public string|int|null $subjectId = null,
    ) {}
}
