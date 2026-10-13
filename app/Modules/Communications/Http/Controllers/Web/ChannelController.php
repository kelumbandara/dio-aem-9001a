<?php

namespace App\Modules\Communications\Http\Controllers\Web;

use App\Modules\Communications\Models\Identity;
use App\Modules\Communications\Services\Address;
use App\Modules\Communications\Services\IdentityService;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** "My phone numbers and e-mail addresses": prove an address is yours with a code. Open to every signed-in person. */
class ChannelController extends Controller
{
    public function index(Request $request): View
    {
        return view('communications::channels.index', [
            'identities' => Identity::query()->where('user_id', $request->user()->id)->orderBy('id')->get()->map(fn (Identity $i) => ['id' => $i->public_id, 'channel' => $i->channel, 'address' => Address::mask($i->channel, $i->address), 'at' => $i->verified_at])->all(),
            'pending' => $request->session()->get('comm_pending'),
        ]);
    }

    public function start(Request $request, IdentityService $identities): RedirectResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'whatsapp'])], 'address' => ['required', 'string', 'max:190']]);

        try {
            $identities->start($request->user()->id, $data['channel'], $data['address']);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('comm.channels.index')->with('comm_pending', $data['channel'])->with('status', __('communications::ui.flash.code_sent'));
    }

    public function confirm(Request $request, IdentityService $identities): RedirectResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'whatsapp'])], 'code' => ['required', 'string', 'max:12']]);

        try {
            $identities->confirm($request->user()->id, $data['channel'], $data['code']);
        } catch (BusinessException $e) {
            return redirect()->route('comm.channels.index')->with('comm_pending', $data['channel'])->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('comm.channels.index')->with('status', __('communications::ui.flash.linked'));
    }

    public function unlink(Request $request, string $identity, IdentityService $identities): RedirectResponse
    {
        $identities->unlink($request->user()->id, $identity);

        return redirect()->route('comm.channels.index')->with('status', __('communications::ui.flash.unlinked'));
    }
}
