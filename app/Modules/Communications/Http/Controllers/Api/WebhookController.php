<?php

namespace App\Modules\Communications\Http\Controllers\Api;

use App\Modules\Communications\Services\WhatsAppWebhook;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** WhatsApp's calls. Always answers quickly; WhatsApp repeats anything that is not answered with 200. */
class WebhookController extends Controller
{
    public function verify(Request $request, WhatsAppWebhook $webhook): Response
    {
        // PHP turns the dots of hub.mode, hub.verify_token and hub.challenge into underscores
        return response($webhook->challenge($request->query('hub_mode'), $request->query('hub_verify_token'), $request->query('hub_challenge')), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppWebhook $webhook): Response
    {
        $webhook->handle($request->getContent(), $request->header('X-Hub-Signature-256'));

        return response('', 200);
    }
}
