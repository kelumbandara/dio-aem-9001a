<?php

namespace App\Modules\Core\Exceptions;

use App\Support\Exceptions\BusinessException;

final class ModuleException extends BusinessException
{
    public static function disabled(string $key): self
    {
        return new self('core.module_disabled', "The {$key} module is disabled.", 403);
    }

    public static function unknown(string $key): self
    {
        return new self('core.module_unknown', "Unknown module: {$key}.", 404);
    }

    public static function required(string $key): self
    {
        return new self('core.module_required', "The {$key} module cannot be disabled.", 422);
    }

    /**
     * @param  list<string>  $missing
     */
    public static function missingDependencies(string $key, array $missing): self
    {
        return new self(
            'core.module_dependency_disabled',
            "Cannot enable {$key}: requires ".implode(', ', $missing).'.',
            422,
        );
    }

    /**
     * @param  list<string>  $dependents
     */
    public static function hasEnabledDependents(string $key, array $dependents): self
    {
        return new self(
            'core.module_has_dependents',
            "Cannot disable {$key}: still required by ".implode(', ', $dependents).'.',
            422,
        );
    }
}
