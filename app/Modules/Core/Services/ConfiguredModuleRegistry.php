<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Core\DTOs\ModuleDefinition;
use App\Modules\Core\Exceptions\ModuleException;
use App\Modules\Core\Models\ModuleSetting;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class ConfiguredModuleRegistry implements ModuleRegistry
{
    private const CACHE_KEY = 'core.module_settings';

    /** @var array<string, list<string>> */
    private array $permissions = [];

    /** @var array<string, list<MenuItem>> */
    private array $menu = [];

    /**
     * @param  array<string, ModuleDefinition>  $definitions
     */
    public function __construct(
        private array $definitions,
        private readonly AuditLogger $audit,
    ) {}

    public function all(): array
    {
        return $this->definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function isEnabled(string $key): bool
    {
        $definition = $this->definitions[$key] ?? null;
        if ($definition === null) {
            return false;
        }
        if ($definition->required) {
            return true;
        }

        return $this->overrides()[$key] ?? $definition->defaultEnabled;
    }

    public function assertEnabled(string $key): void
    {
        if (! $this->has($key)) {
            throw ModuleException::unknown($key);
        }
        if (! $this->isEnabled($key)) {
            throw ModuleException::disabled($key);
        }
    }

    public function enable(string $key, ?int $actorId = null): void
    {
        $definition = $this->definition($key);

        $missing = array_values(array_filter($definition->dependsOn, fn (string $dep) => ! $this->isEnabled($dep)));
        if ($missing !== []) {
            throw ModuleException::missingDependencies($key, $missing);
        }

        $this->persist($key, true, $actorId);
    }

    public function disable(string $key, ?int $actorId = null): void
    {
        $definition = $this->definition($key);

        if ($definition->required) {
            throw ModuleException::required($key);
        }

        $dependents = [];
        foreach ($this->definitions as $other) {
            if (in_array($key, $other->dependsOn, true) && $this->isEnabled($other->key)) {
                $dependents[] = $other->key;
            }
        }
        if ($dependents !== []) {
            throw ModuleException::hasEnabledDependents($key, $dependents);
        }

        $this->persist($key, false, $actorId);
    }

    public function declarePermissions(string $module, array $permissions): void
    {
        $this->permissions[$module] = $permissions;
    }

    public function permissions(bool $onlyEnabled = true): array
    {
        $names = [];
        foreach ($this->permissions as $module => $permissions) {
            if (! $onlyEnabled || $this->isEnabled($module)) {
                array_push($names, ...$permissions);
            }
        }

        return array_values(array_unique($names));
    }

    public function declareMenu(string $module, array $items): void
    {
        $this->menu[$module] = array_map(fn (MenuItem $item) => $item->forModule($module), $items);
    }

    public function menuFor(?Authorizable $user): array
    {
        if ($user === null) {
            return [];
        }

        $visible = [];
        foreach ($this->menu as $module => $items) {
            if (! $this->isEnabled($module)) {
                continue;
            }
            foreach ($items as $item) {
                if ($item->permission === null || $user->can($item->permission)) {
                    $visible[] = $item;
                }
            }
        }

        usort($visible, fn (MenuItem $a, MenuItem $b) => [$a->order, $a->labelKey] <=> [$b->order, $b->labelKey]);

        return $visible;
    }

    private function definition(string $key): ModuleDefinition
    {
        return $this->definitions[$key] ?? throw ModuleException::unknown($key);
    }

    private function persist(string $key, bool $enabled, ?int $actorId): void
    {
        $previous = $this->isEnabled($key);

        ModuleSetting::query()->updateOrCreate(['module' => $key], ['enabled' => $enabled, 'updated_by' => $actorId]);
        Cache::forget(self::CACHE_KEY);

        if ($previous !== $enabled) {
            $this->audit->record($enabled ? 'module.enabled' : 'module.disabled', null, [], [], ['module' => $key]);
        }
    }

    /** @return array<string, bool> */
    private function overrides(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            if (! Schema::hasTable('module_settings')) {
                return [];
            }

            return ModuleSetting::query()->pluck('enabled', 'module')->map(fn ($v) => (bool) $v)->all();
        });
    }
}
