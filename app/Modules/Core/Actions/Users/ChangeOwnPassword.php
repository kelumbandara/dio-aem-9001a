<?php

namespace App\Modules\Core\Actions\Users;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AccessRevoker;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Hash;

/** A signed-in person changes their own password: the old one must be given. Other browsers and phones are signed out. */
final class ChangeOwnPassword
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccessRevoker $revoker) {}

    /** @throws BusinessException auth.current_password_wrong */
    public function __invoke(User $user, string $current, string $new, ?string $keepSessionId = null): void
    {
        if (! Hash::check($current, $user->password)) {
            $this->audit->record('auth.password_change_failed', $user);
            throw new BusinessException('auth.current_password_wrong', 'The current password is not right.', 422, [['field' => 'current_password', 'message' => 'The current password is not right.']]);
        }

        $user->forceFill(['password' => $new])->save();
        $this->revoker->revoke($user, $keepSessionId);
        $this->audit->record('user.password_changed', $user);
    }
}
