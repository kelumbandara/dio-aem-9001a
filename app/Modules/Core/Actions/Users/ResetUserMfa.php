<?php

namespace App\Modules\Core\Actions\Users;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AccessRevoker;
use App\Modules\Core\Services\MfaService;
use App\Support\Exceptions\BusinessException;

/** An administrator removes someone's authenticator (lost phone). Their sign-ins end; privileged roles must enrol again at the next sign-in. */
final class ResetUserMfa
{
    public function __construct(private readonly MfaService $mfa, private readonly AccessRevoker $revoker, private readonly AuditLogger $audit) {}

    /** @throws BusinessException core.user_forbidden */
    public function __invoke(User $actor, User $user): void
    {
        if ($user->hasRole(CoreServiceProvider::SUPER_ADMIN_ROLE) && ! $actor->hasRole(CoreServiceProvider::SUPER_ADMIN_ROLE)) {
            throw new BusinessException('core.user_forbidden', 'Only a super-admin can change a super-admin.', 403);
        }

        $this->mfa->disable($user);
        $this->revoker->revoke($user);
        $this->audit->record('user.mfa_reset', $user, [], [], ['by' => $actor->id]);
    }
}
