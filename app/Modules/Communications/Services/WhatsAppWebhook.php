<?php

namespace App\Modules\Communications\Services;

use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Models\Identity;
use App\Modules\Communications\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calls from WhatsApp: the one-time address check when the webhook is registered, then delivery reports and incoming messages.
 *  - Every call must carry Meta's signature (HMAC-SHA256 of the exact body with the app secret); anything else is refused and nothing in it is read.
 *  - Each report or message is recorded once by its id (the shared inbox table), so a repeated or replayed call changes nothing; anything older than a week is ignored.
 *  - A delivery report only moves a message forward (sent, delivered, read), or to failed while it has not been delivered.
 *  - An incoming message is kept (with the user it belongs to, when the number is a linked one); a stop word opts that number out at once.
 */
final class WhatsAppWebhook
{
    private const RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    public function __construct(private readonly ConsentService $consents) {}

    /** Answer to Meta's GET when the webhook address is registered: the challenge, only if the shared word matches. */
    public function challenge(?string $mode, ?string $token, ?string $challenge): string
    {
        $expected = (string) config('communications.whatsapp.verify_token');
        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, (string) $token)) {
            throw CommunicationsException::make('comm.webhook_forbidden', 'The check word does not match.', 403);
        }

        return (string) $challenge;
    }

    /**
     * @return array{statuses: int, messages: int, ignored: int}
     *
     * @throws CommunicationsException comm.webhook_unconfigured (503) | comm.signature_invalid (401)
     */
    public function handle(string $body, ?string $signature): array
    {
        $secret = (string) config('communications.whatsapp.app_secret');
        if ($secret === '') {
            throw CommunicationsException::make('comm.webhook_unconfigured', 'The WhatsApp webhook is not set up on this server.', 503);
        }
        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);
        if ($signature === null || ! hash_equals($expected, $signature)) {
            throw CommunicationsException::make('comm.signature_invalid', 'The signature is wrong.', 401);
        }

        $count = ['statuses' => 0, 'messages' => 0, 'ignored' => 0];
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return $count;
        }
        $hash = hash('sha256', $body);
        $oldest = CarbonImmutable::now()->subDays(7)->getTimestamp();

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                foreach ((array) ($value['statuses'] ?? []) as $s) {
                    $ts = (int) ($s['timestamp'] ?? 0);
                    if (! $this->fresh($ts, $oldest) || ! $this->once(((string) ($s['id'] ?? '')).':'.((string) ($s['status'] ?? '')), $hash)) {
                        $count['ignored']++;

                        continue;
                    }
                    $this->status($s, $ts);
                    $count['statuses']++;
                }
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $ts = (int) ($m['timestamp'] ?? 0);
                    if (! $this->fresh($ts, $oldest) || ! $this->once((string) ($m['id'] ?? ''), $hash)) {
                        $count['ignored']++;

                        continue;
                    }
                    $this->incoming($m, $ts);
                    $count['messages']++;
                }
            }
        }

        return $count;
    }

    private function fresh(int $timestamp, int $oldest): bool
    {
        return $timestamp === 0 || $timestamp >= $oldest;
    }

    /** True the first time an id is seen. */
    private function once(string $id, string $hash): bool
    {
        if ($id === '' || $id === ':') {
            return false;
        }

        return DB::table('inbox_events')->insertOrIgnore(['source' => 'whatsapp', 'message_id' => mb_substr($id, 0, 255), 'payload_hash' => $hash, 'received_at' => now(), 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]) === 1;
    }

    /** @param  array<string, mixed>  $s */
    private function status(array $s, int $ts): void
    {
        $m = Message::query()->where('channel', 'whatsapp')->where('direction', 'out')->where('provider_message_id', (string) ($s['id'] ?? ''))->first();
        if ($m === null) {
            return;
        }
        $at = $ts > 0 ? CarbonImmutable::createFromTimestamp($ts) : CarbonImmutable::now();
        $new = (string) ($s['status'] ?? '');

        if ($new === 'failed') {
            if (in_array($m->status, ['sent', 'queued', 'sending'], true)) {
                $error = (array) (($s['errors'][0] ?? []));
                $m->forceFill(['status' => 'failed', 'failed_at' => $at, 'error_code' => 'comm.whatsapp_failed', 'error' => mb_substr((string) ($error['title'] ?? $error['message'] ?? 'WhatsApp could not deliver the message.'), 0, 500)])->save();
            }

            return;
        }
        if (! isset(self::RANK[$new]) || (self::RANK[$m->status] ?? 0) >= self::RANK[$new]) {
            return;                                                       // only forward
        }
        $m->forceFill(['status' => $new] + match ($new) {
            'sent' => ['sent_at' => $m->sent_at ?? $at],
            'delivered' => ['delivered_at' => $at, 'sent_at' => $m->sent_at ?? $at],
            default => ['read_at' => $at, 'delivered_at' => $m->delivered_at ?? $at, 'sent_at' => $m->sent_at ?? $at],
        })->save();
    }

    /** @param  array<string, mixed>  $m */
    private function incoming(array $m, int $ts): void
    {
        $address = preg_replace('/\D/', '', (string) ($m['from'] ?? '')) ?? '';
        if ($address === '') {
            return;
        }
        $text = trim((string) (($m['type'] ?? '') === 'text' ? ($m['text']['body'] ?? '') : '['.($m['type'] ?? 'message').']'));
        $identity = Identity::query()->where('channel', 'whatsapp')->where('address', $address)->first();

        Message::query()->create([
            'direction' => 'in', 'channel' => 'whatsapp', 'status' => 'received', 'address' => $address, 'party_type' => $identity === null ? null : 'user', 'party_id' => $identity?->user_id,
            'body' => mb_substr($text, 0, 4000), 'provider_message_id' => (string) ($m['id'] ?? ''), 'locale' => 'en',
        ]);

        $word = mb_strtolower(trim(preg_replace('/[\s\p{P}]+/u', ' ', $text) ?? ''));
        if ($word !== '' && in_array($word, array_map('mb_strtolower', (array) config('communications.stop_words')), true)) {
            $this->consents->record('whatsapp', $address, 'opted_out', 'keyword', 'Replied "'.mb_substr($word, 0, 40).'"', null, $identity === null ? null : 'user', $identity?->user_id);
        }
    }
}
