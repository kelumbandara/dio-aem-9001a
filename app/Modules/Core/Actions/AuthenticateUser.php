<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\MfaService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Hash;

/**
 * Credential (+ TOTP) check shared by the web login and the API token endpoint.
 * Does not start a session or issue a token; the caller does that.
 */
final class AuthenticateUser
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BusinessException auth.invalid_credentials | auth.mfa_required | auth.mfa_invalid | auth.account_disabled
     */
    public function __invoke(string $email, string $password, ?string $mfaCode = null): User
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        // Hash::check also runs for unknown emails so response time does not reveal which exist.
        $passwordOk = Hash::check($password, $user->password ?? Hash::make('timing-equaliser'));

        if ($user === null || ! $passwordOk) {
            $this->audit->record('auth.login_failed', null, [], [], ['email' => mb_strtolower($email)]);
            throw new BusinessException('auth.invalid_credentials', 'These credentials do not match our records.', 401);
        }

        if (! $user->is_active || $user->user_type !== User::TYPE_INTERNAL) {
            $this->audit->record('auth.login_blocked', $user, [], [], ['reason' => 'inactive_or_external']);
            throw new BusinessException('auth.account_disabled', 'This account cannot sign in.', 403);
        }

        if ($user->hasMfaEnabled()) {
            if ($mfaCode === null || $mfaCode === '') {
                throw new BusinessException('auth.mfa_required', 'An authentication code is required.', 401);
            }
            if (! $this->mfa->verify($user, $mfaCode)) {
                $this->audit->record('auth.mfa_failed', $user);
                throw new BusinessException('auth.mfa_invalid', 'The authentication code is invalid.', 401);
            }
        }

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();
        $this->audit->record('auth.login', $user);

        return $user;
    }
}
