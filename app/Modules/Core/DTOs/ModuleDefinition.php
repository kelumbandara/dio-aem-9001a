<?php

namespace App\Modules\Core\DTOs;

final readonly class ModuleDefinition
{
    /**
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $provider,
        public array $dependsOn = [],
        public bool $defaultEnabled = true,
        public bool $required = false,
    ) {}

    /**
     * @param  array{name: string, provider: string, depends_on?: list<string>, enabled?: bool, required?: bool}  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            name: $config['name'],
            provider: $config['provider'],
            dependsOn: $config['depends_on'] ?? [],
            defaultEnabled: $config['enabled'] ?? true,
            required: $config['required'] ?? false,
        );
    }
}
