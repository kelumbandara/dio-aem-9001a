<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Core\DTOs\ModuleDefinition;
use App\Modules\Core\Exceptions\ModuleException;
use Illuminate\Contracts\Auth\Access\Authorizable;

interface ModuleRegistry
{
    /** @return array<string, ModuleDefinition> */
    public function all(): array;

    public function has(string $key): bool;

    public function isEnabled(string $key): bool;

    /** @throws ModuleException when the module is unknown or disabled */
    public function assertEnabled(string $key): void;

    /** @throws ModuleException */
    public function enable(string $key, ?int $actorId = null): void;

    /** @throws ModuleException */
    public function disable(string $key, ?int $actorId = null): void;

    /** @param list<string> $permissions */
    public function declarePermissions(string $module, array $permissions): void;

    /**
     * Permissions declared by enabled modules (or all modules when $onlyEnabled is false).
     *
     * @return list<string>
     */
    public function permissions(bool $onlyEnabled = true): array;

    /** @param list<MenuItem> $items */
    public function declareMenu(string $module, array $items): void;

    /**
     * Menu items of enabled modules the user may see, ordered.
     *
     * @return list<MenuItem>
     */
    public function menuFor(?Authorizable $user): array;
}
