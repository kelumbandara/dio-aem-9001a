<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\UserDirectory;
use App\Modules\Core\Models\User;
use Spatie\Permission\Models\Role;

final class EloquentUserDirectory implements UserDirectory
{
    public function roleNames(): array
    {
        return Role::query()->orderBy('name')->pluck('name')->all();
    }

    public function rolesOf(int $userId): array
    {
        $user = User::query()->find($userId);

        return $user === null ? [] : $user->getRoleNames()->all();
    }

    public function userIdsWithRole(string $role, ?int $branchId = null): array
    {
        // User::role() throws for a role that does not exist; an unknown role simply has no members.
        if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
            return [];
        }

        return User::query()
            ->role($role)
            ->where('is_active', true)
            ->where('user_type', User::TYPE_INTERNAL)
            ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isActiveInternal(int $userId): bool
    {
        return User::query()->whereKey($userId)->where('is_active', true)->where('user_type', User::TYPE_INTERNAL)->exists();
    }

    public function activeUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->where('user_type', User::TYPE_INTERNAL)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function names(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return User::withTrashed()->whereIn('id', $userIds)->pluck('name', 'id')->all();
    }
}
