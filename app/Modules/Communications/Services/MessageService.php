<?php

namespace App\Modules\Communications\Services;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Communications\Approvals\MessageSend;
use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Jobs\DeliverMessage;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;
use App\Modules\Communications\Services\Channels\ChannelDriver;
use App\Modules\Communications\Services\Channels\ChannelException;
use App\Modules\Communications\Services\Channels\EmailDriver;
use App\Modules\Communications\Services\Channels\WhatsAppDriver;
use App\Modules\Core\Contracts\Appearance;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\MasterData\Contracts\PartyDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The life of an outgoing message: drafted (a person or the AI assistant prepares it), checked and sent by a person, delivered by a
 * queued job, reported on by the channel. Nothing leaves without passing, in this order: a valid address, an approved template, the
 * address's consent, the daily limit, and — for sensitive templates — an approval. The AI can only draft.
 */
class MessageService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly TemplateRenderer $renderer,
        private readonly PartyDirectory $parties,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $events,
        private readonly Appearance $look,
    ) {}

    /**
     * Prepares a message from a template. Nothing is sent.
     *
     * @param  array<string, mixed>  $data  channel (email|whatsapp), template (key), either party_type (customer|supplier) + party (code) or address; locale?, params?, related_type?, related_id?
     *
     * @throws CommunicationsException
     */
    public function draft(array $data, ?int $by = null, bool $byAi = false): Message
    {
        $channel = (string) ($data['channel'] ?? '');
        if (! in_array($channel, ['email', 'whatsapp'], true)) {
            throw CommunicationsException::make('comm.channel_invalid', 'Choose e-mail or WhatsApp.', 422, 'channel');
        }

        [$partyType, $partyId, $partyName, $raw] = $this->recipient($channel, $data);
        $address = Address::normalize($channel, $raw);
        if ($address === null) {
            throw CommunicationsException::make('comm.address_invalid', $channel === 'email' ? 'That is not a valid e-mail address.' : 'That is not a valid phone number.', 422, 'address');
        }

        $locale = in_array($data['locale'] ?? null, ['en', 'si', 'ta'], true) ? (string) $data['locale'] : 'en';
        $template = $this->template((string) ($data['template'] ?? ''), $channel, $locale);
        $params = (array) ($data['params'] ?? []);
        $params += ['company' => $this->look->appName(), 'name' => $partyName ?? 'Sir or Madam'];
        $params = array_map(fn ($v) => $this->renderer->clean((string) $v), array_filter($params, fn ($v) => is_scalar($v) || $v === null));

        $body = $this->renderer->render($template->body, $params);
        $subject = $template->subject === null ? null : $this->renderer->render($template->subject, $params);

        return Message::query()->create([
            'direction' => 'out', 'channel' => $channel, 'status' => 'draft', 'party_type' => $partyType, 'party_id' => $partyId, 'address' => $address,
            'template_key' => $template->key, 'locale' => $template->locale, 'subject' => $subject, 'body' => $body, 'params' => $params,
            'requested_by' => $by, 'drafted_by_ai' => $byAi, 'related_type' => $data['related_type'] ?? null, 'related_id' => isset($data['related_id']) ? (string) $data['related_id'] : null,
        ]);
    }

    /**
     * Sends a draft (or tries a failed one again): checks everything, then queues it, or opens an approval for a sensitive template.
     *
     * @throws CommunicationsException
     */
    public function send(Message $message, int $by, string $channel = 'web'): Message
    {
        return DB::transaction(function () use ($message, $by, $channel): Message {
            $m = Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            if (! in_array($m->status, ['draft', 'failed'], true) || $m->direction !== 'out') {
                throw CommunicationsException::state('message', $m->status, 'a draft or a failed message');
            }
            $template = $this->checkSendable($m);

            if ($template->sensitive) {
                $decision = $this->approvals->gate(
                    MessageSend::KEY,
                    ['message_id' => $m->id, 'summary' => ($template->key).' to '.Address::mask($m->channel, $m->address), 'channel' => $m->channel],
                    $by,
                    subject: $m,
                    idempotencyKey: 'comm-send-'.$m->public_id.'-'.$m->attempts,
                    channel: $channel === 'api' ? 'api' : 'web',
                );
                if ($decision->requiresApproval) {
                    $m->forceFill(['status' => 'awaiting_approval', 'approval_request_id' => (string) $decision->requestId, 'requested_by' => $by, 'error' => null, 'error_code' => null, 'failed_at' => null])->save();
                    $this->audit->record('comm.message_submitted', $m, ['status' => 'draft'], ['status' => 'awaiting_approval']);

                    return $m->refresh();
                }
            }

            return $this->queue($m, $by);
        });
    }

    /** Runs when an approval for a sensitive message is granted: the checks are made again, because things may have changed since. */
    public function sendApproved(int $messageId): Message
    {
        return DB::transaction(function () use ($messageId): Message {
            $m = Message::query()->whereKey($messageId)->lockForUpdate()->firstOrFail();
            if ($m->status !== 'awaiting_approval') {
                throw CommunicationsException::state('message', $m->status, 'waiting for approval');
            }
            $this->checkSendable($m);

            return $this->queue($m, $m->requested_by);
        });
    }

    public function cancel(Message $message, ?int $by = null): Message
    {
        return DB::transaction(function () use ($message): Message {
            $m = Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            if (! in_array($m->status, ['draft', 'awaiting_approval', 'failed'], true)) {
                throw CommunicationsException::state('message', $m->status, 'a draft, waiting for approval or failed');
            }
            $old = $m->status;
            $m->forceFill(['status' => 'cancelled'])->save();
            $this->audit->record('comm.message_cancelled', $m, ['status' => $old], ['status' => 'cancelled']);

            return $m->refresh();
        });
    }

    /**
     * Called by the queue: sends one queued message. Returns true when the same message should be tried again later.
     */
    public function deliver(int $messageId): bool
    {
        $m = DB::transaction(function () use ($messageId): ?Message {
            $m = Message::query()->whereKey($messageId)->lockForUpdate()->first();
            if ($m === null || $m->status !== 'queued') {
                return null;                                              // already sent, cancelled or being sent: nothing to do
            }
            $m->forceFill(['status' => 'sending', 'attempts' => $m->attempts + 1])->save();

            return $m;
        });
        if ($m === null) {
            return false;
        }

        // an opt-out that arrived while it waited in the queue is honoured
        try {
            $template = $this->template((string) $m->template_key, $m->channel, $m->locale);
            $this->consents->assertMaySend($m->channel, $m->address, $template->purpose);
        } catch (CommunicationsException $e) {
            $m->forceFill(['status' => 'cancelled', 'error_code' => $e->errorCode, 'error' => $e->getMessage()])->save();
            $this->audit->record('comm.message_blocked', $m, ['status' => 'queued'], ['status' => 'cancelled', 'reason' => $e->errorCode]);

            return false;
        }

        try {
            $id = $this->driver($m->channel)->send($m, $template);
        } catch (ChannelException $e) {
            $retry = $e->retryable && $m->attempts < (int) config('communications.send_attempts');
            $m->forceFill($retry
                ? ['status' => 'queued', 'error_code' => $e->errorCode, 'error' => $e->getMessage()]
                : ['status' => 'failed', 'error_code' => $e->errorCode, 'error' => $e->getMessage(), 'failed_at' => CarbonImmutable::now()])->save();
            if (! $retry) {
                $this->events->record('comm.message.failed', ['message_id' => $m->public_id, 'channel' => $m->channel, 'error_code' => $e->errorCode], 'comm_message', $m->id);
            }

            return $retry;
        }

        $m->forceFill(['status' => 'sent', 'provider_message_id' => $id, 'sent_at' => CarbonImmutable::now(), 'error' => null, 'error_code' => null])->save();
        $this->audit->record('comm.message_sent', $m, ['status' => 'queued'], ['status' => 'sent', 'channel' => $m->channel, 'to' => Address::mask($m->channel, $m->address)]);
        $this->events->record('comm.message.sent', ['message_id' => $m->public_id, 'channel' => $m->channel, 'party_type' => $m->party_type, 'party_id' => $m->party_id], 'comm_message', $m->id);

        return false;
    }

    /** Every check a message must pass before it may leave. Returns the template it will use. */
    private function checkSendable(Message $m): Template
    {
        $template = $this->template((string) $m->template_key, $m->channel, $m->locale);
        if (! $this->driver($m->channel)->configured()) {
            throw CommunicationsException::make('comm.channel_not_configured', $m->channel === 'whatsapp' ? 'WhatsApp is not set up on this server yet.' : 'E-mail is not set up on this server yet.', 422, 'channel');
        }
        if (Address::normalize($m->channel, $m->address) === null) {
            throw CommunicationsException::make('comm.address_invalid', 'The address is not valid any more.', 422, 'address');
        }
        $this->consents->assertMaySend($m->channel, $m->address, $template->purpose);

        $since = CarbonImmutable::now()->subDay();
        $recent = Message::query()->where('direction', 'out')->where('channel', $m->channel)->where('address', $m->address)->whereKeyNot($m->id)
            ->whereIn('status', ['queued', 'sending', 'sent', 'delivered', 'read'])->where('created_at', '>', $since)->count();
        if ($recent >= (int) config('communications.max_per_address_per_day')) {
            throw CommunicationsException::make('comm.too_many', 'Too many messages have already gone to this address today.', 429, 'address');
        }

        return $template;
    }

    private function queue(Message $m, ?int $by): Message
    {
        $m->forceFill(['status' => 'queued', 'queued_at' => CarbonImmutable::now(), 'error' => null, 'error_code' => null, 'failed_at' => null, 'requested_by' => $by ?? $m->requested_by])->save();
        $this->audit->record('comm.message_queued', $m, [], ['status' => 'queued', 'channel' => $m->channel, 'to' => Address::mask($m->channel, $m->address)]);
        DeliverMessage::dispatch($m->id);

        return $m->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?int, 2: ?string, 3: string} party type, id, name, raw address
     */
    private function recipient(string $channel, array $data): array
    {
        $type = $data['party_type'] ?? null;
        if ($type === null || $type === '') {
            $raw = trim((string) ($data['address'] ?? ''));
            if ($raw === '') {
                throw CommunicationsException::make('comm.recipient_required', 'Choose who the message is for.', 422, 'party');
            }

            return [null, null, null, $raw];
        }
        if (! in_array($type, ['customer', 'supplier'], true)) {
            throw CommunicationsException::make('comm.recipient_invalid', 'A message goes to a customer or a supplier.', 422, 'party_type');
        }
        $code = trim((string) ($data['party'] ?? ''));
        $party = $type === 'customer' ? $this->parties->customerByCode($code) : $this->parties->supplierByCode($code);
        if ($party === null) {
            throw CommunicationsException::make('comm.party_not_found', "There is no {$type} with that code.", 422, 'party');
        }
        $contact = $this->parties->contact($type, $party->id);
        $raw = (string) ($channel === 'email' ? $contact['email'] : $contact['phone']);
        if (trim($raw) === '') {
            throw CommunicationsException::make('comm.no_address', "{$party->name} has no ".($channel === 'email' ? 'e-mail address' : 'phone number').' on file.', 422, 'party');
        }

        return [$type, $party->id, $party->name, $raw];
    }

    /** @throws CommunicationsException comm.template_unavailable */
    private function template(string $key, string $channel, string $locale, bool $requireApproved = true): Template
    {
        $q = Template::query()->where('key', $key)->where('channel', $channel)->where('is_active', true);
        $t = (clone $q)->where('locale', $locale)->first() ?? (clone $q)->where('locale', 'en')->first();
        $unusable = $t === null || ($requireApproved && $channel === 'whatsapp' && (! $t->approved || (string) $t->provider_template === ''));
        if ($unusable) {
            throw CommunicationsException::make('comm.template_unavailable', $channel === 'whatsapp' ? 'There is no WhatsApp-approved template for that message.' : 'There is no such message template.', 422, 'template');
        }

        return $t;
    }

    private function driver(string $channel): ChannelDriver
    {
        return $channel === 'whatsapp' ? app(WhatsAppDriver::class) : app(EmailDriver::class);
    }
}
