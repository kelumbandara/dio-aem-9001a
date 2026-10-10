<?php

namespace App\Support;

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\DTOs\MenuItem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base class for module providers (docs/architecture/modules.md §3).
 *
 * Convention: a provider lives in the module root and the module follows the folder layout
 * (Database/Migrations, Routes/{web,api}.php, Views, Lang). Everything that exists is loaded;
 * routes are always registered and guarded at request time by the `module:<key>` middleware, so
 * toggling a module needs no route cache rebuild.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Unique lower-case module key, matching config/modules.php. */
    abstract protected function moduleKey(): string;

    /**
     * Permission names (`module.resource.action`) this module introduces.
     *
     * @return list<string>
     */
    protected function permissions(): array
    {
        return [];
    }

    /**
     * Sidebar entries this module contributes.
     *
     * @return list<MenuItem>
     */
    protected function menu(): array
    {
        return [];
    }

    public function boot(): void
    {
        $key = $this->moduleKey();
        $path = dirname((string) (new ReflectionClass($this))->getFileName());

        if (is_dir("{$path}/Database/Migrations")) {
            $this->loadMigrationsFrom("{$path}/Database/Migrations");
        }
        if (is_dir("{$path}/Lang")) {
            $this->loadTranslationsFrom("{$path}/Lang", $key);
        }
        if (is_dir("{$path}/Views")) {
            $this->loadViewsFrom("{$path}/Views", $key);
        }

        if (is_file("{$path}/Routes/api.php")) {
            Route::middleware(['api', "module:{$key}"])
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group("{$path}/Routes/api.php");
        }
        if (is_file("{$path}/Routes/web.php")) {
            Route::middleware(['web', "module:{$key}"])->group("{$path}/Routes/web.php");
        }

        $registry = $this->app->make(ModuleRegistry::class);
        $registry->declarePermissions($key, $this->permissions());
        $registry->declareMenu($key, $this->menu());
    }
}
