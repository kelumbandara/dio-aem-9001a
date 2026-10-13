<?php

namespace App\Modules\Communications\Http\Controllers\Web;

use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Services\ConsentService;
use App\Support\Http\Controller;
use Illuminate\View\View;

/** The "stop these e-mails" link: opts the address of that message out at once. Opening it again changes nothing more. */
class UnsubscribeController extends Controller
{
    public function __invoke(string $message, ConsentService $consents): View
    {
        $m = Message::query()->where('public_id', $message)->where('direction', 'out')->firstOrFail();
        if ($consents->status($m->channel, $m->address) !== 'opted_out') {
            $consents->record($m->channel, $m->address, 'opted_out', 'unsubscribe_link', 'Used the link in a message', null, $m->party_type, $m->party_id);
        }

        return view('communications::unsubscribed');
    }
}
