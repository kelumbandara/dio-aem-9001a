<?php

namespace App\Modules\Licensing\Http\Controllers\Web;

use App\Modules\Licensing\Models\Licence;
use App\Modules\Licensing\Services\LicenceService;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenceController extends Controller
{
    /** Open to every signed-in user: when the system is locked, this is the one screen people can still reach. */
    public function show(Request $request, LicenceService $licence): View
    {
        return view('licensing::licence.show', [
            'status' => $licence->status(), 'installationId' => $licence->installationId(), 'enforcing' => $licence->enforcing(),
            'history' => Licence::query()->where('installation_id', $licence->installationId())->orderByDesc('issued_at')->orderByDesc('id')->limit(24)->get(),
            'canManage' => $request->user()->can('licensing.manage'),
            'grace' => (int) config('licensing.grace_days'), 'readonly' => (int) config('licensing.readonly_days'), 'renewNote' => config('licensing.renew_note'),
        ]);
    }

    public function store(Request $request, LicenceService $licence): RedirectResponse
    {
        abort_unless($request->user()->can('licensing.manage'), 403);
        $data = $request->validate(['licence' => ['required', 'string', 'max:8000']]);

        try {
            $installed = $licence->install($data['licence'], $request->user()->id);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors(['licence' => $e->getMessage()]);
        }

        return redirect()->route('licence.show')->with('status', __('licensing::ui.installed', ['until' => $installed->valid_until->toDateString()]));
    }
}
