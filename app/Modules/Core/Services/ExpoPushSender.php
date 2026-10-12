<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\MobileDevice;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Sends one notification to every phone a person has registered, through the Expo push service. The text is made here in the
 * person's own language from the same translation keys as the in-app inbox. A device the service reports as gone is forgotten.
 *
 * Not done yet: reading delivery receipts (the service accepts the message; whether the phone got it is not checked).
 */
final class ExpoPushSender
{
    /**
     * @param  array<string, scalar|null>  $params
     * @return int how many devices were handed to the push service
     *
     * @throws RuntimeException when the push service cannot be reached or answers with a server error (the queue retries)
     */
    public function sendToUser(int $userId, string $key, array $params, ?string $url, string $level, ?string $subjectType = null, string|int|null $subjectId = null): int
    {
        if (! (bool) config('mobile.push.enabled')) {
            return 0;
        }
        $devices = MobileDevice::query()->where('user_id', $userId)->orderBy('id')->get();
        if ($devices->isEmpty()) {
            return 0;
        }

        $messages = [];
        foreach ($devices as $device) {
            $locale = in_array($device->locale, ['en', 'si', 'ta'], true) ? $device->locale : config('app.locale');
            $previous = App::getLocale();
            App::setLocale($locale);
            try {
                [$title, $body] = [$this->text("{$key}.title", $params), $this->text("{$key}.body", $params)];
            } finally {
                App::setLocale($previous);
            }
            $messages[] = [
                'to' => $device->push_token, 'title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 178),
                'sound' => 'default', 'priority' => 'high', 'channelId' => 'default',
                'data' => array_filter(['url' => $url, 'level' => $level, 'subject_type' => $subjectType, 'subject_id' => $subjectId], fn ($v) => $v !== null),
            ];
        }

        $request = Http::acceptJson()->asJson()->timeout((int) config('mobile.push.timeout'));
        if ((string) config('mobile.push.access_token') !== '') {
            $request = $request->withToken((string) config('mobile.push.access_token'));
        }
        $res = $request->post((string) config('mobile.push.url'), $messages);
        if ($res->serverError()) {
            throw new RuntimeException('The push service answered with status '.$res->status().'.');
        }
        if (! $res->successful()) {
            return 0;                                                     // a refused request will not get better by repeating it
        }

        $sent = 0;
        foreach ((array) $res->json('data', []) as $i => $ticket) {
            if (($ticket['status'] ?? '') === 'ok') {
                $sent++;
            } elseif (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered' && isset($devices[$i])) {
                $devices[$i]->delete();
            }
        }

        return $sent;
    }

    /** @param  array<string, scalar|null>  $params */
    private function text(string $key, array $params): string
    {
        return Lang::has($key) ? __($key, $params) : $key;
    }
}
