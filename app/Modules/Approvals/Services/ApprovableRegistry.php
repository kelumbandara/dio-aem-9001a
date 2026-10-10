<?php

namespace App\Modules\Approvals\Services;

use App\Modules\Approvals\Contracts\Approvable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

final class ApprovableRegistry
{
    /** @var array<string, class-string<Approvable>> */
    private array $actions = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<Approvable>  $class */
    public function register(string $class): void
    {
        if (! is_subclass_of($class, Approvable::class)) {
            throw new InvalidArgumentException("{$class} must implement ".Approvable::class);
        }

        $key = $class::approvalKey();
        if (isset($this->actions[$key]) && $this->actions[$key] !== $class) {
            throw new LogicException("Approval key '{$key}' is already registered by {$this->actions[$key]}.");
        }

        $this->actions[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->actions);
    }

    public function get(string $key): Approvable
    {
        $class = $this->actions[$key] ?? throw new LogicException("No approvable action registered for '{$key}'.");

        return $this->container->make($class);
    }
}
