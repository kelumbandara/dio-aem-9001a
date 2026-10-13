<?php

namespace App\Modules\Communications\Services\Channels;

use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Meta's WhatsApp Business Cloud API. A message to someone who has not just written to us must use a template Meta has approved,
 * so only template messages are sent. The access token is read from the environment at the moment of sending and goes only into
 * the request header; it is never logged, stored or shown in an error.
 */
final class WhatsAppDriver implements ChannelDriver
{
    public function configured(): bool
    {
        return (string) config('communications.whatsapp.token') !== '' && (string) config('communications.whatsapp.phone_number_id') !== '';
    }

    public function send(Message $message, ?Template $template): string
    {
        if (! $this->configured()) {
            throw new ChannelException('comm.whatsapp_not_configured', 'WhatsApp is not set up on this server yet.', false);
        }
        if ($template === null || (string) $template->provider_template === '' || ! $template->approved) {
            throw new ChannelException('comm.whatsapp_template_required', 'WhatsApp can only send a template that WhatsApp has approved.', false);
        }

        $params = (array) $message->params;
        $parameters = array_map(fn (string $name) => ['type' => 'text', 'text' => (string) ($params[$name] ?? '')], (array) $template->param_order);
        $body = [
            'messaging_product' => 'whatsapp', 'to' => $message->address, 'type' => 'template',
            'template' => ['name' => $template->provider_template, 'language' => ['code' => $template->provider_language ?: $template->locale]]
                + ($parameters === [] ? [] : ['components' => [['type' => 'body', 'parameters' => $parameters]]]),
        ];
        $url = rtrim((string) config('communications.whatsapp.graph_url'), '/').'/'.config('communications.whatsapp.api_version').'/'.config('communications.whatsapp.phone_number_id').'/messages';

        try {
            $res = Http::withToken((string) config('communications.whatsapp.token'))->acceptJson()->asJson()->timeout((int) config('communications.whatsapp.timeout'))->post($url, $body);
        } catch (ConnectionException) {
            throw new ChannelException('comm.whatsapp_unreachable', 'WhatsApp could not be reached.', true);
        }

        if ($res->serverError() || $res->status() === 429) {
            throw new ChannelException('comm.whatsapp_busy', 'WhatsApp is busy or down (status '.$res->status().').', true);
        }
        if (! $res->successful()) {
            $why = trim(str_replace((string) config('communications.whatsapp.token'), '[token]', (string) $res->json('error.message', '')));
            throw new ChannelException('comm.whatsapp_refused', 'WhatsApp refused the message'.($why !== '' ? ': '.mb_substr($why, 0, 200) : ' (status '.$res->status().')').'.', false);
        }
        $id = (string) $res->json('messages.0.id', '');
        if ($id === '') {
            throw new ChannelException('comm.whatsapp_refused', 'WhatsApp answered without a message id.', false);
        }

        return $id;
    }
}
