<?php

namespace App\Modules\Communications\Services;

use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Models\Identity;
use App\Modules\Communications\Models\IdentityChallenge;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;
use App\Modules\Communications\Services\Channels\ChannelDriver;
use App\Modules\Communications\Services\Channels\ChannelException;
use App\Modules\Communications\Services\Channels\EmailDriver;
use App\Modules\Communications\Services\Channels\WhatsAppDriver;
use App\Modules\Core\Contracts\Appearance;
use App\Modules\Core\Contracts\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proves that a phone number or e-mail address belongs to a user: a six-digit code is sent to it and the user types it back.
 * The code lives ten minutes, allows five tries, and at most three codes can be asked for per hour; only a fingerprint of it is
 * stored (never the code), and it is not written into the message history. A number can belong to one person only. Linking also
 * records the person's permission to be contacted there.
 */
final class IdentityService
{
    public function __construct(private readonly ConsentService $consents, private readonly TemplateRenderer $renderer, private readonly AuditLogger $audit, private readonly Appearance $look) {}

    /**
     * @throws CommunicationsException
     */
    public function start(int $userId, string $channel, string $raw): void
    {
        if (! in_array($channel, ['email', 'whatsapp'], true)) {
            throw CommunicationsException::make('comm.channel_invalid', 'Choose e-mail or WhatsApp.', 422, 'channel');
        }
        $address = Address::normalize($channel, $raw);
        if ($address === null) {
            throw CommunicationsException::make('comm.address_invalid', $channel === 'email' ? 'That is not a valid e-mail address.' : 'That is not a valid phone number.', 422, 'address');
        }
        $taken = Identity::query()->where('channel', $channel)->where('address', $address)->first();
        if ($taken !== null) {
            throw CommunicationsException::make($taken->user_id === $userId ? 'comm.already_linked' : 'comm.address_taken', $taken->user_id === $userId ? 'This is already linked to you.' : 'This address already belongs to someone else.', 422, 'address');
        }
        if ($this->consents->status($channel, $address) === 'opted_out') {
            throw CommunicationsException::make('comm.opted_out', 'This address has asked not to be contacted. Ask an administrator to record its permission first.', 422, 'address');
        }
        $recent = IdentityChallenge::query()->where('user_id', $userId)->where('created_at', '>', CarbonImmutable::now()->subHour())->count();
        if ($recent >= (int) config('communications.otp.max_per_hour')) {
            throw CommunicationsException::make('comm.too_many_codes', 'Too many codes were asked for. Please wait an hour.', 429);
        }
        $driver = $this->driver($channel);
        if (! $driver->configured()) {
            throw CommunicationsException::make('comm.channel_not_configured', $channel === 'whatsapp' ? 'WhatsApp is not set up on this server yet.' : 'E-mail is not set up on this server yet.', 422, 'channel');
        }
        $template = Template::query()->where('key', 'identity_code')->where('channel', $channel)->where('is_active', true)->where('locale', 'en')->first();
        if ($template === null || ($channel === 'whatsapp' && (! $template->approved || (string) $template->provider_template === ''))) {
            throw CommunicationsException::make('comm.template_unavailable', 'The verification message is not available on this channel.', 422, 'template');
        }

        $code = (string) random_int(100000, 999999);
        $params = ['code' => $code, 'minutes' => (string) config('communications.otp.ttl_minutes'), 'company' => $this->look->appName()];
        // sent without being saved: the code must not sit in the message history
        $transient = new Message(['direction' => 'out', 'channel' => $channel, 'address' => $address, 'template_key' => 'identity_code', 'locale' => 'en',
            'subject' => $template->subject === null ? null : $this->renderer->render($template->subject, $params), 'body' => $this->renderer->render($template->body, $params), 'params' => $params]);
        $transient->public_id = (string) Str::uuid();

        try {
            $providerId = $driver->send($transient, $template);
        } catch (ChannelException $e) {
            throw CommunicationsException::make('comm.send_failed', 'The code could not be sent: '.$e->getMessage(), 503);
        }

        DB::transaction(function () use ($userId, $channel, $address, $code, $providerId): void {
            IdentityChallenge::query()->where('user_id', $userId)->where('channel', $channel)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            IdentityChallenge::query()->create(['user_id' => $userId, 'channel' => $channel, 'address' => $address, 'code_hash' => $this->hash($code, $userId, $address),
                'expires_at' => CarbonImmutable::now()->addMinutes((int) config('communications.otp.ttl_minutes')), 'attempts' => 0]);
            Message::query()->create(['direction' => 'out', 'channel' => $channel, 'status' => 'sent', 'party_type' => 'user', 'party_id' => $userId, 'address' => $address, 'template_key' => 'identity_code',
                'body' => 'A verification code was sent (the code itself is not kept).', 'provider_message_id' => $providerId, 'sent_at' => now(), 'requested_by' => $userId]);
        });
    }

    /**
     * @throws CommunicationsException comm.code_invalid | comm.code_expired | comm.too_many_attempts | comm.address_taken
     */
    public function confirm(int $userId, string $channel, string $code): Identity
    {
        // the outcome is decided inside the transaction but reported after it: a wrong code must still be counted
        $outcome = DB::transaction(function () use ($userId, $channel, $code): Identity|string {
            $c = IdentityChallenge::query()->where('user_id', $userId)->where('channel', $channel)->whereNull('consumed_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($c === null || $c->expires_at->isPast()) {
                return 'expired';
            }
            if ($c->attempts >= (int) config('communications.otp.max_attempts')) {
                return 'attempts';
            }
            if (! hash_equals($c->code_hash, $this->hash(trim($code), $userId, $c->address))) {
                $c->forceFill(['attempts' => $c->attempts + 1])->save();

                return 'invalid';
            }
            if (Identity::query()->where('channel', $channel)->where('address', $c->address)->exists()) {
                return 'taken';
            }

            $c->forceFill(['consumed_at' => now()])->save();
            $identity = Identity::query()->create(['user_id' => $userId, 'channel' => $channel, 'address' => $c->address, 'verified_at' => now()]);
            $this->consents->record($channel, $c->address, 'opted_in', 'identity_link', 'Linked and verified by the user', $userId, 'user', $userId);
            $this->audit->record('comm.identity_linked', $identity, [], ['channel' => $channel, 'address' => Address::mask($channel, $c->address)]);

            return $identity;
        });

        return match ($outcome) {
            'expired' => throw CommunicationsException::make('comm.code_expired', 'The code has expired. Ask for a new one.', 422, 'code'),
            'attempts' => throw CommunicationsException::make('comm.too_many_attempts', 'Too many wrong codes. Ask for a new one.', 429, 'code'),
            'invalid' => throw CommunicationsException::make('comm.code_invalid', 'That code is not right.', 422, 'code'),
            'taken' => throw CommunicationsException::make('comm.address_taken', 'This address already belongs to someone.', 422, 'address'),
            default => $outcome,
        };
    }

    public function unlink(int $userId, string $publicId): void
    {
        $identity = Identity::query()->where('user_id', $userId)->where('public_id', $publicId)->firstOrFail();
        $this->audit->record('comm.identity_unlinked', $identity, ['channel' => $identity->channel, 'address' => Address::mask($identity->channel, $identity->address)], []);
        $identity->delete();
    }

    private function hash(string $code, int $userId, string $address): string
    {
        return hash_hmac('sha256', $code.'|'.$userId.'|'.$address, (string) config('app.key'));
    }

    private function driver(string $channel): ChannelDriver
    {
        return $channel === 'whatsapp' ? app(WhatsAppDriver::class) : app(EmailDriver::class);
    }
}
