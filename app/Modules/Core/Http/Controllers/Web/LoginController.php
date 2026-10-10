<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Actions\AuthenticateUser;
use App\Modules\Core\Http\Requests\WebLoginRequest;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('core::auth.login');
    }

    public function store(WebLoginRequest $request, AuthenticateUser $authenticate): RedirectResponse
    {
        try {
            $user = $authenticate($request->validated('email'), $request->validated('password'), $request->validated('mfa_code'));
        } catch (BusinessException $e) {
            // Field the message belongs to: the code box for MFA problems, the email box otherwise.
            $field = str_starts_with($e->errorCode, 'auth.mfa') ? 'mfa_code' : 'email';

            return back()->withInput($request->only('email'))->withErrors([$field => $e->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
