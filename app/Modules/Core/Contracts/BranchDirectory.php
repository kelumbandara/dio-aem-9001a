<?php

namespace App\Modules\Core\Contracts;

/** Read access to branches (sites) for modules above Core. */
interface BranchDirectory
{
    /** Id of the active branch with this code, or null. */
    public function idByCode(string $code): ?int;

    /**
     * @param  list<int>  $branchIds
     * @return array<int, string> branch id => "CODE — name"
     */
    public function labels(array $branchIds): array;

    /** @return array<int, string> active branches, id => "CODE — name" */
    public function active(): array;
}
