<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Activity;
use App\Services\MfaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Password, then authenticator code. The session is only signed in after both. */
class AuthController extends Controller
{
    public function __construct(private readonly MfaService $mfa, private readonly Activity $activity) {}

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login|'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.'])->onlyInput('email');
        }

        $user = User::query()->where('email', $data['email'])->where('active', true)->first();
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            $this->activity->log('auth.failed', null, ['email' => $data['email']]);

            return back()->withErrors(['email' => 'These details are not correct.'])->onlyInput('email');
        }
        RateLimiter::clear($key);

        $request->session()->regenerate();
        $request->session()->put('mfa.user', $user->id);

        return redirect()->route($user->mfa_confirmed_at === null ? 'mfa.setup' : 'mfa.challenge');
    }

    public function showSetup(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null || $user->mfa_confirmed_at !== null) {
            return redirect()->route('login');
        }

        return view('auth.mfa-setup', $this->mfa->pending($user));
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('login');
        }
        $code = $request->validate(['code' => 'required|string'])['code'];
        if (! $this->throttleOk($request, $user) || ! $this->mfa->confirm($user, $code)) {
            RateLimiter::hit('mfa|'.$user->id, 60);

            return back()->withErrors(['code' => 'That code is not correct.']);
        }

        return $this->completeLogin($request, $user);
    }

    public function showChallenge(Request $request): View|RedirectResponse
    {
        return $this->pendingUser($request) === null ? redirect()->route('login') : view('auth.mfa-challenge');
    }

    public function challenge(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('login');
        }
        $code = $request->validate(['code' => 'required|string'])['code'];
        if (! $this->throttleOk($request, $user) || ! $this->mfa->verify($user, $code)) {
            RateLimiter::hit('mfa|'.$user->id, 60);

            return back()->withErrors(['code' => 'That code is not correct.']);
        }

        return $this->completeLogin($request, $user);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('mfa.user');

        return $id === null ? null : User::query()->where('active', true)->find($id);
    }

    private function throttleOk(Request $request, User $user): bool
    {
        return ! RateLimiter::tooManyAttempts('mfa|'.$user->id, 5);
    }

    private function completeLogin(Request $request, User $user): RedirectResponse
    {
        $request->session()->forget('mfa.user');
        Auth::login($user);
        $request->session()->regenerate();
        $this->activity->log('auth.signed_in', $user, [], $user->id);

        return redirect()->route('dashboard');
    }
}
