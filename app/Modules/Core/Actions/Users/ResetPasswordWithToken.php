<?php

namespace App\Modules\Core\Actions\Users;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AccessRevoker;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Sets a new password from a mailed link (valid once, for a limited time). The password rules are checked by the caller. Ends every existing sign-in. */
final class ResetPasswordWithToken
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccessRevoker $revoker) {}

    /** @throws BusinessException auth.reset_invalid */
    public function __invoke(string $email, string $token, string $password): User
    {
        $changed = null;
        $status = Password::broker()->reset(
            ['email' => mb_strtolower(trim($email)), 'token' => $token, 'password' => $password],
            function (User $user, string $new) use (&$changed): void {
                $user->forceFill(['password' => $new, 'remember_token' => Str::random(60)])->save();
                $changed = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || $changed === null) {
            throw new BusinessException('auth.reset_invalid', 'This link is not valid any more. Ask for a new one.', 422);
        }

        $this->revoker->revoke($changed);
        $this->audit->record('user.password_reset', $changed);

        return $changed;
    }
}
