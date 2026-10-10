<?php

namespace App\Modules\Core\Contracts;

interface SettingsService
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_BRANCH = 'branch';

    /**
     * Resolve a setting: exact scope, then global, then the configured default, then $default.
     */
    public function get(string $key, mixed $default = null, string $scope = self::SCOPE_GLOBAL, int $scopeId = 0): mixed;

    public function set(string $key, mixed $value, string $scope = self::SCOPE_GLOBAL, int $scopeId = 0): void;

    public function forget(string $key, string $scope = self::SCOPE_GLOBAL, int $scopeId = 0): void;
}
