<?php

namespace App\Modules\Communications\Services;

use App\Modules\Communications\Exceptions\CommunicationsException;
use App\Modules\Communications\Models\Consent;

/**
 * What an address has allowed. The rules:
 *  - an address that has **opted out** is never written to, on that channel, whatever the message (an opt-out is effective at once);
 *  - **WhatsApp** needs an explicit opt-in before any message (the platform's own rule, and the law's);
 *  - **marketing** messages need an explicit opt-in on any channel;
 *  - ordinary business e-mail (an order, an invoice, a reminder) may go to an address that has not opted out.
 * Every change is a new line in the history (who, when, why); the history is never edited.
 */
final class ConsentService
{
    public const SOURCES = ['manual', 'keyword', 'identity_link', 'unsubscribe_link', 'import'];

    /** 'opted_in', 'opted_out' or 'unknown' (nothing recorded). */
    public function status(string $channel, string $address): string
    {
        return Consent::query()->where('channel', $channel)->where('address', $address)->orderByDesc('id')->value('status') ?? 'unknown';
    }

    public function record(string $channel, string $address, string $status, string $source, ?string $note = null, ?int $by = null, ?string $partyType = null, ?int $partyId = null): Consent
    {
        return Consent::query()->create([
            'channel' => $channel, 'address' => $address, 'status' => $status, 'source' => $source, 'note' => $note === null ? null : mb_substr($note, 0, 255),
            'party_type' => $partyType, 'party_id' => $partyId, 'recorded_by' => $by,
        ]);
    }

    /**
     * @throws CommunicationsException comm.opted_out | comm.no_consent
     */
    public function assertMaySend(string $channel, string $address, string $purpose): void
    {
        $status = $this->status($channel, $address);
        if ($status === 'opted_out') {
            throw CommunicationsException::make('comm.opted_out', 'This address has asked not to be contacted on this channel.', 422, 'address');
        }
        if ($status !== 'opted_in' && ($channel === 'whatsapp' || $purpose === 'marketing')) {
            throw CommunicationsException::make('comm.no_consent', $channel === 'whatsapp' ? 'WhatsApp messages need the person\'s permission first; record it on the Consents page.' : 'Marketing messages need the person\'s permission first.', 422, 'address');
        }
    }

    /**
     * Newest line first, for the screen.
     *
     * @return list<Consent>
     */
    public function history(string $channel, string $address, int $limit = 20): array
    {
        return Consent::query()->where('channel', $channel)->where('address', $address)->orderByDesc('id')->limit($limit)->get()->all();
    }
}
