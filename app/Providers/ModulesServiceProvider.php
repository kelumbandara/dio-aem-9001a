<?php

namespace App\Providers;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\DTOs\ModuleDefinition;
use App\Modules\Core\Services\ConfiguredModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Builds the module registry from config/modules.php and registers every module's provider,
 * in the order they are listed (dependencies first).
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $modules = config('modules.modules', []);

        $this->app->singleton(ModuleRegistry::class, function ($app) use ($modules) {
            $definitions = [];
            foreach ($modules as $key => $config) {
                $definitions[$key] = ModuleDefinition::fromConfig($key, $config);
            }

            return new ConfiguredModuleRegistry($definitions, $app->make(AuditLogger::class));
        });

        foreach ($modules as $config) {
            $this->app->register($config['provider']);
        }
    }
}
