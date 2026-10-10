<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Services\MfaService;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MfaController extends Controller
{
    public function setup(Request $request, MfaService $mfa): View|RedirectResponse
    {
        if ($request->user()->hasMfaEnabled()) {
            return redirect()->route('dashboard');
        }

        return view('core::auth.mfa-setup', ['setup' => $mfa->pendingOrBegin($request->user())]);
    }

    public function confirm(Request $request, MfaService $mfa): View|RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $recoveryCodes = $mfa->confirm($request->user(), $data['code']);
        if ($recoveryCodes === null) {
            return back()->withErrors(['code' => __('core::auth.mfa_code_invalid')]);
        }

        return view('core::auth.mfa-recovery-codes', ['codes' => $recoveryCodes]);
    }
}
