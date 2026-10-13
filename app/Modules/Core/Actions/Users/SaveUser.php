<?php

namespace App\Modules\Core\Actions\Users;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AccessRevoker;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates or changes an internal user and their roles. A new user gets no usable password: they set their own through the
 * link that {@see SendPasswordLink} mails them.
 *
 * Rules that stop an administrator from lifting themselves (or anyone) above their own level:
 *  - only a super-admin may give the super-admin role or change a super-admin;
 *  - nobody may give a role that carries permissions they do not hold themselves;
 *  - nobody may change their own roles or switch themselves off.
 */
final class SaveUser
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccessRevoker $revoker, private readonly SendPasswordLink $sendLink) {}

    /**
     * @param  array{name: string, email: string, locale?: ?string, is_active?: bool, roles?: list<string>}  $data
     *
     * @throws BusinessException core.user_forbidden | core.user_role_forbidden | core.user_self_change
     */
    public function __invoke(User $actor, array $data, ?User $user = null): User
    {
        $roles = array_values(array_unique($data['roles'] ?? []));
        $isNew = $user === null;

        if (! $isNew) {
            if ($user->is($actor) && (array_key_exists('roles', $data) && $this->roleNames($user) !== $this->sorted($roles) || ($data['is_active'] ?? true) === false)) {
                throw new BusinessException('core.user_self_change', 'You cannot change your own roles or switch yourself off.', 403);
            }
            if ($user->hasRole(CoreServiceProvider::SUPER_ADMIN_ROLE) && ! $actor->hasRole(CoreServiceProvider::SUPER_ADMIN_ROLE)) {
                throw new BusinessException('core.user_forbidden', 'Only a super-admin can change a super-admin.', 403);
            }
        }
        $this->assertRolesAllowed($actor, $roles, $isNew ? [] : $this->roleNames($user));

        return DB::transaction(function () use ($actor, $data, $roles, $user, $isNew): User {
            $before = $isNew ? [] : $this->roleNames($user);
            $user ??= new User(['password' => Str::random(64), 'user_type' => User::TYPE_INTERNAL]);
            $user->fill([
                'name' => trim($data['name']), 'email' => mb_strtolower(trim($data['email'])),
                'locale' => $data['locale'] ?? $user->locale,
                'is_active' => $data['is_active'] ?? ($isNew ? true : $user->is_active),
            ]);
            $wasActive = $user->getOriginal('is_active');
            $user->save();

            if (array_key_exists('roles', $data)) {
                $user->syncRoles($roles);
            }
            $after = $this->roleNames($user);
            if ($before !== $after) {
                $this->audit->record('user.roles_changed', $user, ['roles' => $before], ['roles' => $after], ['by' => $actor->id]);
            }
            if (! $isNew && $wasActive && ! $user->is_active) {
                $this->revoker->revoke($user);
                $this->audit->record('user.deactivated', $user, [], [], ['by' => $actor->id]);
            }
            if ($isNew) {
                $this->sendLink($user);
            }

            return $user;
        });
    }

    /** @param  list<string>  $wanted
     * @param  list<string>  $current */
    private function assertRolesAllowed(User $actor, array $wanted, array $current): void
    {
        $actorIsSuper = $actor->hasRole(CoreServiceProvider::SUPER_ADMIN_ROLE);
        foreach (array_diff($wanted, $current) as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if ($role === null) {
                throw new BusinessException('core.user_role_forbidden', "There is no role “{$name}”.", 422, [['field' => 'roles', 'message' => "There is no role “{$name}”."]]);
            }
            if ($actorIsSuper) {
                continue;
            }
            $lacking = $role->name === CoreServiceProvider::SUPER_ADMIN_ROLE
                ? ['everything']
                : $role->permissions->pluck('name')->reject(fn (string $p) => $actor->can($p))->values()->all();
            if ($lacking !== []) {
                throw new BusinessException('core.user_role_forbidden', "You cannot give the role “{$name}”: it allows things you are not allowed yourself.", 403, [['field' => 'roles', 'message' => "You cannot give “{$name}”."]]);
            }
        }
    }

    /** @return list<string> */
    private function roleNames(User $user): array
    {
        return $this->sorted($user->roles()->pluck('name')->all());
    }

    /** @param  list<string>  $names
     * @return list<string> */
    private function sorted(array $names): array
    {
        sort($names);

        return array_values($names);
    }

    private function sendLink(User $user): void
    {
        ($this->sendLink)($user);
    }
}
