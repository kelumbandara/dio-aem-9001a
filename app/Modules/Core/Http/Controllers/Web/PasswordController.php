<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Actions\Users\ChangeOwnPassword;
use App\Modules\Core\Actions\Users\ResetPasswordWithToken;
use App\Modules\Core\Actions\Users\SendPasswordLink;
use App\Modules\Core\Services\PasswordPolicy;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Forgot password (by mailed link), choosing a password from a link, and changing your own password. */
class PasswordController extends Controller
{
    public function request(): View
    {
        return view('core::auth.forgot-password');
    }

    public function email(Request $request, SendPasswordLink $send): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $send($data['email']);

        // The same answer whether or not the address has an account.
        return back()->with('status', __('core::ui.password.link_requested'));
    }

    public function reset(Request $request, string $token): View
    {
        return view('core::auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request, PasswordPolicy $policy, ResetPasswordWithToken $reset): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'], 'email' => ['required', 'email', 'max:190'],
            'password' => [...$policy->rules((string) $request->input('email')), 'confirmed'],
        ]);

        try {
            $reset($data['email'], $data['token'], $data['password']);
        } catch (BusinessException $e) {
            return back()->withInput($request->only('email'))->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('login')->with('status', __('core::ui.password.reset_done'));
    }

    public function edit(): View
    {
        return view('core::account.password');
    }

    public function change(Request $request, PasswordPolicy $policy, ChangeOwnPassword $change): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => [...$policy->rules($request->user()->email), 'confirmed', 'different:current_password'],
        ]);

        try {
            $change($request->user(), $data['current_password'], $data['password'], $request->session()->getId());
        } catch (BusinessException $e) {
            return back()->withErrors([($e->details[0]['field'] ?? 'current_password') => $e->getMessage()]);
        }

        return redirect()->route('account.password')->with('status', __('core::ui.password.changed'));
    }
}
