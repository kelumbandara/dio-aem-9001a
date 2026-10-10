<?php

namespace App\Modules\Core\Contracts;

/**
 * Read-only view of users and roles for modules above Core, which must not import Core's models.
 */
interface UserDirectory
{
    /** @return list<string> every role name */
    public function roleNames(): array;

    /** @return list<string> role names held by the user */
    public function rolesOf(int $userId): array;

    /**
     * Ids of active internal users holding the role. With a branch id, only users of that branch
     * or without a branch (head-office staff) are returned.
     *
     * @return list<int>
     */
    public function userIdsWithRole(string $role, ?int $branchId = null): array;

    /** Active internal user that may act in the system. */
    public function isActiveInternal(int $userId): bool;

    /**
     * Active internal users for pickers (approvers, delegates).
     *
     * @return array<int, string> id => display name, ordered by name
     */
    public function activeUsers(): array;

    /**
     * @param  list<int>  $userIds
     * @return array<int, string> id => display name (unknown ids are omitted)
     */
    public function names(array $userIds): array;
}
