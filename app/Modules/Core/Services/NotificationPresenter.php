<?php

namespace App\Modules\Core\Services;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Lang;

/** Renders stored notification keys in the current locale (the reader's, not the sender's). */
final class NotificationPresenter
{
    /**
     * @return array{id: string, title: string, body: string, url: ?string, level: string, read: bool, created_at: string}
     */
    public function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $key = (string) ($data['key'] ?? '');
        $params = (array) ($data['params'] ?? []);

        return [
            'id' => $notification->id,
            'title' => $this->translate("{$key}.title", $params),
            'body' => $this->translate("{$key}.body", $params),
            'url' => $data['url'] ?? null,
            'level' => $data['level'] ?? 'info',
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $params */
    private function translate(string $key, array $params): string
    {
        // Show the key rather than nothing if a translation is missing, so the gap is visible.
        return Lang::has($key) ? __($key, $params) : $key;
    }
}
