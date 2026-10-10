<?php

namespace Tests\Support;

use PhpToken;

/**
 * Enforces docs/architecture/modules.md §2 by scanning module source for references to other
 * modules' namespaces (via tokens, so comments and strings are ignored).
 *
 *  - A module may reference another module only if it (transitively) depends on it, and only its
 *    public namespaces (Contracts, DTOs, Events).
 *  - Every folder under the modules path must be declared in config/modules.php.
 */
final class ModuleBoundaryChecker
{
    private const ROOT_NAMESPACE = 'App\\Modules\\';

    /**
     * @param  array<string, list<string>>  $dependsOn  module key (folder name, any case) => direct dependencies
     * @param  list<string>  $publicNamespaces
     */
    public function __construct(
        private readonly string $modulesPath,
        private readonly array $dependsOn,
        private readonly array $publicNamespaces,
    ) {}

    /** @return list<string> human-readable violations (empty = clean) */
    public function violations(): array
    {
        $declared = [];
        foreach (array_keys($this->dependsOn) as $key) {
            $declared[strtolower($key)] = $key;
        }

        $violations = [];

        foreach (glob($this->modulesPath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $folder = basename($dir);
            $key = $declared[strtolower($folder)] ?? null;

            if ($key === null) {
                $violations[] = "Module folder '{$folder}' is not declared in config/modules.php";

                continue;
            }

            $allowed = $this->allowedModules($key);

            foreach ($this->phpFiles($dir) as $file) {
                foreach ($this->referencedModuleClasses((string) file_get_contents($file)) as $reference) {
                    [$otherFolder, $rest] = $reference;
                    $relative = str_replace('\\', '/', substr($file, strlen($this->modulesPath) + 1));

                    if (strtolower($otherFolder) === strtolower($folder)) {
                        continue;
                    }

                    $otherKey = $declared[strtolower($otherFolder)] ?? null;
                    if ($otherKey === null || ! in_array($otherKey, $allowed, true)) {
                        $violations[] = "{$relative}: {$folder} must not depend on {$otherFolder} (App\\Modules\\{$otherFolder}\\{$rest})";

                        continue;
                    }

                    $segment = explode('\\', $rest)[0];
                    if (! in_array($segment, $this->publicNamespaces, true)) {
                        $violations[] = "{$relative}: {$folder} uses non-public {$otherFolder}\\{$segment} (App\\Modules\\{$otherFolder}\\{$rest}); only ".implode('/', $this->publicNamespaces).' are public';
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<string> keys of every module $key may depend on, directly or transitively */
    private function allowedModules(string $key): array
    {
        $seen = [];
        $queue = $this->dependsOn[$key] ?? [];

        while ($queue !== []) {
            $next = array_shift($queue);
            if (in_array($next, $seen, true)) {
                continue;
            }
            $seen[] = $next;
            array_push($queue, ...($this->dependsOn[$next] ?? []));
        }

        return $seen;
    }

    /** @return \Generator<string> */
    private function phpFiles(string $dir): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /** @return list<array{string, string}> [module folder, remainder of the class name] */
    private function referencedModuleClasses(string $source): array
    {
        $found = [];

        foreach (PhpToken::tokenize($source) as $token) {
            if (! in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = ltrim($token->text, '\\');
            if (str_starts_with($name, self::ROOT_NAMESPACE)) {
                $parts = explode('\\', substr($name, strlen(self::ROOT_NAMESPACE)), 2);
                $found[] = [$parts[0], $parts[1] ?? ''];
            }
        }

        return $found;
    }
}
