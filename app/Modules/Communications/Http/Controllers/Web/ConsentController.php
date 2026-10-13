<?php

namespace App\Modules\Communications\Http\Controllers\Web;

use App\Modules\Communications\Http\Controllers\Concerns\PresentsMessages;
use App\Modules\Communications\Services\Address;
use App\Modules\Communications\Services\ConsentService;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConsentController extends Controller
{
    use PresentsMessages;

    /** Look an address up: its status and the history behind it; and (with the permission) record a change. */
    public function index(Request $request, ConsentService $consents): View
    {
        $this->needs($request, 'comm.message.view');
        $channel = in_array($request->query('channel'), ['email', 'whatsapp'], true) ? (string) $request->query('channel') : 'email';
        $raw = trim((string) $request->query('address', ''));
        $address = $raw === '' ? null : Address::normalize($channel, $raw);

        return view('communications::consents.index', [
            'channel' => $channel, 'raw' => $raw, 'address' => $address, 'invalid' => $raw !== '' && $address === null,
            'status' => $address === null ? null : $consents->status($channel, $address), 'history' => $address === null ? [] : $consents->history($channel, $address),
            'canManage' => $request->user()->can('comm.consent.manage'),
        ]);
    }

    public function store(Request $request, ConsentService $consents): RedirectResponse
    {
        $this->needs($request, 'comm.consent.manage');
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'whatsapp'])], 'address' => ['required', 'string', 'max:190'], 'status' => ['required', Rule::in(['opted_in', 'opted_out'])], 'note' => ['required', 'string', 'max:255']]);
        $address = Address::normalize($data['channel'], $data['address']);
        if ($address === null) {
            return back()->withInput()->withErrors(['address' => __('communications::ui.consents.invalid')]);
        }
        $consents->record($data['channel'], $address, $data['status'], 'manual', $data['note'], $request->user()->id);

        return redirect()->route('comm.consents.index', ['channel' => $data['channel'], 'address' => $data['address']])->with('status', __('communications::ui.flash.consent_saved'));
    }
}
