<?php

namespace App\Modules\Core\Http\Middleware;

use App\Support\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Privileged roles (config core.mfa.required_roles) must enrol TOTP before using anything else.
 * Place after the auth middleware; leave MFA enrolment and logout routes outside this group.
 */
final class EnforceMfaEnrollment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->requiresMfa() && ! $user->hasMfaEnabled()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                throw new BusinessException(
                    'auth.mfa_enrollment_required',
                    'Multi-factor authentication must be set up before you can continue.',
                    403,
                );
            }

            return redirect()->route('mfa.setup');
        }

        return $next($request);
    }
}
